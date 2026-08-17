<?php

declare(strict_types=1);

return [
    'test_subject' => 'Test message from :hotel',
    'test_heading' => 'Your mail is working',
    'test_body' => 'This is a test message from :hotel. If you are reading it, your hotel can send confirmations to guests.',
    'test_instruction' => 'Type this code back into Admin → Mail to confirm it arrived.',
    'test_signoff' => 'You can delete this message.',

    'spf_note' => 'Tells other mail servers that this host may send mail as your domain. Without it your confirmations are likely to be filed as spam.',
    'dmarc_note' => 'Tells other servers what to do with mail that fails the checks. Starts at "quarantine" rather than "reject" on purpose — a hotel that rejects on day one and has SPF slightly wrong stops delivering its own confirmations.',
    'dkim_note' => 'Your mail provider gives you this value and the selector to replace in the host. Signing proves the message was not altered in transit.',
    'dkim_value' => '(the value your mail provider gives you)',
];
