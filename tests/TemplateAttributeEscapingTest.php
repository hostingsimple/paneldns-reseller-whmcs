<?php

use PHPUnit\Framework\TestCase;

/**
 * TPL-ATTR-01 (v1.9.6): values in the delete-confirmation handlers are HTML-escaped data
 * attributes, never json_encode output inside a double-quoted attribute.
 *
 * Up to 1.9.5 zones-list.tpl and zone-records.tpl had
 * onsubmit="return confirm('…' + {$zone.name|json_encode} + '…');". json_encode emits
 * literal double quotes, which ended the attribute early: the handler was a syntax error,
 * so the browser submitted "Delete" with no confirmation, and the template was an
 * attribute-injection point held shut only by zone-name validation.
 *
 * The opening <form> tags are rendered here with the two Smarty modifiers they use
 * (escape = htmlspecialchars ENT_QUOTES, json_encode) and parsed as HTML.
 */
final class TemplateAttributeEscapingTest extends TestCase
{
    private const DIR = __DIR__ . '/../modules/servers/paneldnsreseller/templates/client/';

    private static function source(string $tpl): string
    {
        // Smarty comments are not output.
        return (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents(self::DIR . $tpl));
    }

    /** Renders {$a.b|mod|mod} expressions from $vars; other Smarty tags are left out. */
    private static function render(string $src, array $vars): string
    {
        return (string) preg_replace_callback('/\{\$([a-z_]+(?:\.[a-z_]+)*)((?:\|[a-z_]+(?::\'[a-z]+\')?)*)\}/i', function ($m) use ($vars) {
            $value = $vars[$m[1]] ?? '';
            foreach (array_filter(explode('|', $m[2])) as $mod) {
                $value = match (explode(':', $mod)[0]) {
                    'json_encode' => json_encode($value),
                    'escape'      => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
                    default       => $value,
                };
            }
            return (string) $value;
        }, $src);
    }

    /** The opening <form …> tag whose action is $action, rendered, as a DOM element. */
    private static function formTag(string $tpl, string $action, array $vars): \DOMElement
    {
        $src = self::source($tpl);
        if (!preg_match('/<form\b[^>]*customAction=' . preg_quote($action, '/') . '[^>]*>/s', $src, $m)) {
            self::fail("{$tpl}: no form for {$action}");
        }
        $doc = new \DOMDocument();
        @$doc->loadHTML('<!doctype html><html><body>' . self::render($m[0], $vars) . '</form></body></html>');
        return $doc->getElementsByTagName('form')->item(0);
    }

    public function test_the_zone_delete_confirmation_survives_rendering(): void
    {
        $form = self::formTag('zones-list.tpl', 'do-zone-delete', ['zone.name' => 'example.com', 'service_id' => 500]);

        $onsubmit = $form->getAttribute('onsubmit');
        $this->assertStringStartsWith("return confirm('Delete the entire zone ' + ", $onsubmit);
        $this->assertStringEndsWith("? This cannot be undone.');", $onsubmit, 'the handler must not be cut short');
        $this->assertStringNotContainsString('example.com', $onsubmit, 'no server value inside the handler');
        $this->assertSame('example.com', $form->getAttribute('data-zone-name'));
    }

    public function test_the_record_delete_confirmation_survives_rendering(): void
    {
        $form = self::formTag('zone-records.tpl', 'do-record-delete', ['r.type' => 'TXT', 'service_id' => 500]);

        $this->assertSame("return confirm('Delete this ' + this.getAttribute('data-record-type') + ' record?');", $form->getAttribute('onsubmit'));
        $this->assertSame('TXT', $form->getAttribute('data-record-type'));
    }

    public function test_markup_in_a_value_stays_inside_its_attribute(): void
    {
        $hostile = 'x" onmouseover="alert(1)" a="<b>\'';
        $form = self::formTag('zones-list.tpl', 'do-zone-delete', ['zone.name' => $hostile, 'service_id' => 500]);

        $this->assertSame($hostile, $form->getAttribute('data-zone-name'));
        $this->assertFalse($form->hasAttribute('onmouseover'));
    }

    public function test_no_template_outputs_a_variable_inside_an_event_handler_or_json_outside_a_script(): void
    {
        foreach (glob(self::DIR . '*.tpl') as $path) {
            $src = self::source(basename($path));
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"[^"]*\{\$/i', $src,
                basename($path) . ': server values reach a handler through an escaped data- attribute');

            $outsideScripts = (string) preg_replace('/<script\b.*?<\/script>/is', '', $src);
            $this->assertStringNotContainsString('json_encode', $outsideScripts,
                basename($path) . ': json_encode output belongs in a <script> block, not an attribute');
        }
    }
}
