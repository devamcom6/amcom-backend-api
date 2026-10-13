<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_keeps_allowed_markup(): void
    {
        $html = '<h2>Title</h2><p>Text <strong>bold</strong> <a href="https://amcom.test">link</a></p><ul><li>One</li></ul>';
        $clean = HtmlSanitizer::clean($html);

        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringContainsString('href="https://amcom.test"', $clean);
        $this->assertStringContainsString('<li>One</li>', $clean);
    }

    public function test_removes_scripts_handlers_and_dangerous_links(): void
    {
        $html = '<p onclick="steal()">Hi<script>alert(1)</script></p><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)><iframe src="https://evil"></iframe><div><em>kept</em></div>';
        $clean = HtmlSanitizer::clean($html);

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringContainsString('<em>kept</em>', $clean);
        $this->assertStringContainsString('<p>Hi</p>', $clean);
    }
}
