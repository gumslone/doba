<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Commands\GenerateOpenApi;
use Illuminate\Testing\TestResponse;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Checks a real response against what openapi.yaml promises.
 *
 * The documented schemas are `additionalProperties: false` with every
 * field required, so this catches a field added, removed, renamed or
 * changed in type — which is precisely the set of changes that breaks a
 * partner's parser and that nothing else in the suite would notice.
 */
final class Contract
{
    private const URI = 'spec://doba/openapi.json';

    private static ?string $json = null;

    /**
     * A fresh copy every time, deliberately.
     *
     * SchemaStorage rewrites a document's `$ref`s to absolute URIs in
     * place when it takes it, so handing out one shared object means the
     * second assertion in a test reads a spec the first one edited.
     */
    public static function spec(): stdClass
    {
        self::$json ??= GenerateOpenApi::render();

        /** @var stdClass $spec */
        $spec = json_decode(self::$json, false, 512, JSON_THROW_ON_ERROR);

        return $spec;
    }

    /**
     * @param  string  $path  The templated path as written in the spec, e.g. `/bookings/{reference}`.
     */
    public static function assertMatches(TestResponse $response, string $method, string $path): void
    {
        $status = (string) $response->getStatusCode();
        $operation = self::operation($method, $path);

        Assert::assertObjectHasProperty(
            $status,
            $operation->responses,
            sprintf('%s %s returned %s, which openapi.yaml does not document.', strtoupper($method), $path, $status),
        );

        // A response may be written as `$ref: '#/components/responses/…'`,
        // so follow it before asking what it contains — and keep the
        // pointer to where it landed, because that is where the schema is.
        [$documented, $pointer] = self::follow(
            $operation->responses->{$status},
            sprintf('paths/%s/%s/responses/%s', self::pointer($path), strtolower($method), $status),
        );

        $body = $response->getContent();

        if (! isset($documented->content)) {
            // A documented response with no content — 204. Anything in the
            // body would be a surprise to a partner that is not reading one.
            Assert::assertSame('', (string) $body, sprintf('%s %s %s is documented as having no body.', strtoupper($method), $path, $status));

            return;
        }

        $type = (array) $documented->content;
        $mediaType = array_key_first($type);

        Assert::assertStringContainsString(
            (string) $mediaType,
            (string) $response->headers->get('Content-Type'),
            sprintf('%s %s %s is documented as %s.', strtoupper($method), $path, $status, $mediaType),
        );

        self::assertBody(
            json_decode((string) $body, false, 512, JSON_THROW_ON_ERROR),
            sprintf('%s#/%s/content/%s/schema', self::URI, $pointer, self::pointer((string) $mediaType)),
            sprintf('%s %s -> %s', strtoupper($method), $path, $status),
        );
    }

    private static function assertBody(mixed $data, string $ref, string $what): void
    {
        $storage = new SchemaStorage;
        $storage->addSchema(self::URI, self::spec());

        $validator = new Validator(new Factory($storage));
        $validator->validate($data, (object) ['$ref' => $ref]);

        if ($validator->isValid()) {
            return;
        }

        $errors = array_map(
            static fn (array $e): string => sprintf('  %s: %s', $e['property'] === '' ? '(root)' : $e['property'], $e['message']),
            $validator->getErrors(),
        );

        Assert::fail(sprintf(
            "%s does not match openapi.yaml:\n%s\n\nEither the change was not meant to be visible to partners, or the spec needs updating.",
            $what,
            implode("\n", $errors),
        ));
    }

    /**
     * Follow local `$ref`s to the node they name, reporting where it is.
     *
     * @return array{0:stdClass,1:string}
     */
    private static function follow(stdClass $node, string $pointer): array
    {
        while (isset($node->{'$ref'})) {
            $pointer = ltrim((string) $node->{'$ref'}, '#/');
            $node = self::spec();

            foreach (explode('/', $pointer) as $segment) {
                $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $segment)};
            }
        }

        return [$node, $pointer];
    }

    /**
     * RFC 6901: `~` before `/`, or escaping the escape breaks the escape.
     *
     * The `+` is not RFC 6901's doing. The validator urldecodes every
     * pointer segment, which turns the `+` in `application/problem+json`
     * into a space and then cannot find the media type it was handed.
     * Percent-encoding it survives that round trip.
     */
    public static function pointer(string $segment): string
    {
        return str_replace(['~', '/', '+'], ['~0', '~1', '%2B'], $segment);
    }

    public static function operation(string $method, string $path): stdClass
    {
        $paths = self::spec()->paths;

        Assert::assertObjectHasProperty($path, $paths, sprintf('openapi.yaml documents no path %s.', $path));
        Assert::assertObjectHasProperty(
            strtolower($method),
            $paths->{$path},
            sprintf('openapi.yaml documents no %s on %s.', strtoupper($method), $path),
        );

        return $paths->{$path}->{strtolower($method)};
    }
}
