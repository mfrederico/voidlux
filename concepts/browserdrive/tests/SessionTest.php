<?php
use PHPUnit\Framework\TestCase;
use app\concepts\browserdrive\Session;

/** What can be checked without a database: which paths a session may open, and which commands reach a browser. */
class SessionTest extends TestCase {
    private array $allow = ['/demo', '/sandbox/*'];

    public function testAnAllowedPathPasses(): void {
        $this->assertSame('/demo', Session::matchPath('/demo', $this->allow));
        $this->assertSame('/sandbox/floor/2?x=1', Session::matchPath('/sandbox/floor/2?x=1', $this->allow));
        $this->assertSame('/sandbox', Session::matchPath('/sandbox', $this->allow));
    }
    public function testExactPatternsAreExact(): void {
        $this->expectException(\InvalidArgumentException::class); Session::matchPath('/demo/other', $this->allow);
    }
    public function testOtherSitesAndTricksAreRefused(): void {
        foreach (['https://evil.example/', '//evil.example/x', '/sandbox/../admin', 'demo', '/sandbox\\..\\x', '', '/sandboxevil', '/sandbox-x/y'] as $p) {
            try { Session::matchPath($p, $this->allow); $this->fail("{$p} passed"); } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testNothingAllowedIsAnErrorThatSaysWhatToDo(): void {
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('/browserdrive'); Session::matchPath('/demo', []);
    }
    public function testCommandsAreChecked(): void {
        $this->assertSame(['do' => 'click', 'target' => 'text=Save'], Session::clean(['do' => 'click', 'target' => 'text=Save']));
        $this->assertSame(['do' => 'press', 'key' => 'Enter', 'hold' => 50], Session::clean(['do' => 'press', 'key' => 'Enter', 'hold' => 50]));
        foreach ([['do' => 'shell'], ['do' => 'click', 'targe' => '#x'], ['do' => 'evaluate'], ['do' => 'evaluate', 'js' => '']] as $bad) {
            try { Session::clean($bad); $this->fail(json_encode($bad) . ' passed'); } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testEveryCommandTheRunnerPlaysIsKnown(): void {
        $runner = file_get_contents(dirname(__DIR__) . '/assets/runner.js');
        foreach (array_keys(Session::COMMANDS) as $c) $this->assertStringContainsString("case '{$c}'", $runner, "the runner does not play {$c}");
    }
}
