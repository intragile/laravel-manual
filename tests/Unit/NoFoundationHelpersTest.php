<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The package requires only illuminate/* components, so it must not call the global helpers that only
 * laravel/framework defines (Illuminate/Foundation/helpers.php).
 */
final class NoFoundationHelpersTest extends TestCase
{
    private const HELPERS = [
        'abort', 'abort_if', 'abort_unless', 'app', 'app_path', 'auth', 'back', 'base_path', 'bcrypt', 'broadcast',
        'cache', 'config', 'config_path', 'cookie', 'csrf_token', 'database_path', 'decrypt', 'dispatch', 'encrypt',
        'event', 'info', 'lang_path', 'logger', 'now', 'old', 'policy', 'public_path', 'redirect', 'report', 'request',
        'rescue', 'resolve', 'resource_path', 'response', 'route', 'session', 'storage_path', 'to_route', 'today',
        'trans', 'url', 'validator', 'view', '__',
    ];

    public function test_src_does_not_call_foundation_helpers(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (self::helperCalls((string) file_get_contents($file->getPathname())) as $call) {
                $found[] = $file->getFilename().':'.$call;
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_guard_sees_qualified_and_relative_calls_only(): void
    {
        $code = "<?php\n\\config('a');\nnamespace\\app();\n\\Foo\\config();\n\$x->config();\nX::now();\n"
            ."function abort() {}\nnew Request();\nnow ();\n";

        $this->assertSame(['2 \\config()', '3 namespace\\app()', '9 now()'], self::helperCalls($code));
    }

    /**
     * Calls of a helper as `line name()`; methods, static calls, declarations and `new` are not calls.
     *
     * @return list<string>
     */
    private static function helperCalls(string $code): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $calls = [];

        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])
                && in_array(self::shortName($token->text), self::HELPERS, true)
                && $next?->text === '('
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                $calls[] = $token->line.' '.$token->text.'()';
            }
        }

        return $calls;
    }

    /**
     * `\config` and `namespace\config` resolve to the global helper too; a qualified name such as
     * `\Foo\config` is a different function.
     */
    private static function shortName(string $name): string
    {
        $name = strtolower($name);

        if (str_starts_with($name, 'namespace\\')) {
            $name = substr($name, strlen('namespace\\'));
        }

        return ltrim($name, '\\');
    }
}
