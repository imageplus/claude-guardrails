<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests\Hooks;

use PHPUnit\Framework\TestCase;

/**
 * Runs a hook script the way Claude Code does: as a separate `php` process,
 * tool-call JSON on stdin, judged by exit code (2 = block, 0 = allow).
 *
 * Each test gets a throwaway project directory as CLAUDE_PROJECT_DIR and cwd,
 * so glob-based checks resolve against fixtures rather than this repository.
 */
abstract class HookTestCase extends TestCase
{
    protected const BLOCK = 2;
    protected const ALLOW = 0;

    protected string $project;

    /** Hook filename in hooks/, e.g. 'block-vapor.php'. */
    abstract protected function hook(): string;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/guardrails-test-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    /** Create a fixture file inside the throwaway project. */
    protected function touch(string $relative): void
    {
        $path = $this->project . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0755, true);
        }
        touch($path);
    }

    /** @return array{0:int,1:string} exit code and stderr */
    protected function runHook(array $payload): array
    {
        $script = \dirname(__DIR__, 2) . '/hooks/' . $this->hook();
        $env    = ['CLAUDE_PROJECT_DIR' => $this->project, 'PATH' => (string) getenv('PATH')];

        $proc = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            $env
        );
        self::assertIsResource($proc, 'Could not start hook process');

        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }

    protected function bash(string $command): int
    {
        return $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]])[0];
    }

    protected function file(string $tool, string $path): int
    {
        return $this->runHook(['tool_name' => $tool, 'tool_input' => ['file_path' => $path]])[0];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
