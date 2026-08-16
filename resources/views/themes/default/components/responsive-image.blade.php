@props([
    'media' => null,
    'sizes' => null,
    'eager' => false,
    'class' => '',
])

@if ($media)
    {{-- width/height come from the media row so the browser reserves the box
         before the bytes arrive; without them every image on the page is a
         layout shift, which is the CLS half of the Core Web Vitals budget. --}}
    <img {{ $attributes->merge(\App\Support\Media\ResponsiveImage::attributes($media, $sizes, $eager))->class($class) }}>
@endif
