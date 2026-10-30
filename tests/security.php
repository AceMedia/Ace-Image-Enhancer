<?php
// Run in isolation: php8.4 tests/security.php.
set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
define('ABSPATH', __DIR__);
function add_action() {} function add_filter() {}
function get_option($key, $default) { return $default; }
require dirname(__DIR__) . '/ace-image-enhancer.php';
$instance = new Ace_Image_Enhancer();
$method = new ReflectionMethod($instance, 'sanitize_svg_content'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$wrap = function($body) { return '<svg xmlns="http://www.w3.org/2000/svg">'.$body.'</svg>'; };
$cases = [
 'shape'=>[$wrap('<path d="M0 0 L10 10" fill="red"/>'), true],
 'gradient'=>[$wrap('<defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs><rect fill="url(#g)"/>'),true],
 'icon'=>[$wrap('<defs><path id="p" d="M0 0"/></defs><use href="#p"/>'),true],
 'script'=>[$wrap('<script>alert(1)</script>'),false],
 'event'=>[$wrap('<path onclick="alert(1)"/>'),false],
 'style'=>[$wrap('<path style="fill:red"/>'),false],
 'remote'=>[$wrap('<use href="https://example.com/a.svg#p"/>'),false],
 'foreign'=>[$wrap('<foreignObject/>'),false],
 'namespace'=>[$wrap('<path xmlns="http://www.w3.org/1999/xhtml"/>'),false],
 'malformed'=>['<svg>',false],
 'doctype'=>['<!DOCTYPE svg SYSTEM "file:///etc/passwd">'.$wrap(''),false],
 'entity'=>['<!DOCTYPE svg [<!ENTITY x "abc">]>'.$wrap('&x;'),false],
 'pi'=>['<?xml-stylesheet href="https://example.com/x"?>'.$wrap(''),false],
 'large'=>[$wrap(str_repeat(' ', 5*1024*1024)),false],
 'remote-paint'=>[$wrap('<rect fill="url(https://example.com/x)"/>'),false],
 'data'=>[$wrap('<use href="data:image/svg+xml,evil"/>'),false],
];
foreach ($cases as $name => [$svg, $expected]) {
 if (!class_exists('DOMDocument')) $expected = false;
 $actual = $method->invoke($instance, $svg) !== false;
 if ($actual !== $expected) throw new RuntimeException('FAIL '.$name);
 echo "PASS $name\n";
}
