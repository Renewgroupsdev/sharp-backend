<?php

namespace Tests\Feature;

use App\Support\SafeHtml;
use Tests\TestCase;

class SafeHtmlTest extends TestCase
{
    public function test_formatting_is_kept_and_scripts_and_attributes_are_dropped(): void
    {
        $dirty = '<h2 onclick="x()">Hi</h2><p style="color:red">Some <strong>bold</strong> and <em>italic</em><script>alert(1)</script></p>'
            .'<a href="javascript:alert(1)">bad</a> <a href="https://example.com/a?b=1&c=2" onclick="y()">good</a><img src=x onerror=z()>';

        $clean = SafeHtml::clean($dirty);

        $this->assertStringContainsString('<h2>Hi</h2>', $clean);
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">good</a>', $clean);
    }

    public function test_plain_text_passes_through_and_an_empty_editor_becomes_null(): void
    {
        $this->assertSame('Experience in fullstack development', SafeHtml::clean('  Experience in fullstack development '));
        $this->assertNull(SafeHtml::clean('<p><br></p>'));
        $this->assertNull(SafeHtml::clean('   '));
        $this->assertNull(SafeHtml::clean(null));
    }
}
