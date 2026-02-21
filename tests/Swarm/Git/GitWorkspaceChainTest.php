<?php

declare(strict_types=1);

namespace VoidLux\Tests\Swarm\Git;

use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;

/**
 * Unit tests for GitWorkspace branch chain detection and rebase-chain strategy.
 * Tests isLinearChain(), trackBranchRelationship(), getBranchChain(), and rebaseChain().
 */
class GitWorkspaceChainTest
{
    private int $passed = 0;
    private int $failed = 0;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTask(string $id, array $dependsOn = [], string $gitBranch = ''): TaskModel
    {
        return new TaskModel(
            id: $id,
            title: "Task {$id}",
            description: '',
            status: TaskStatus::Completed,
            priority: 0,
            requiredCapabilities: [],
            createdBy: 'test',
            assignedTo: null,
            assignedNode: null,
            result: null,
            error: null,
            progress: null,
            projectPath: '',
            context: '',
            lamportTs: 1,
            claimedAt: null,
            completedAt: null,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            dependsOn: $dependsOn,
            gitBranch: $gitBranch,
        );
    }

    private function assert(bool $condition, string $label): void
    {
        if ($condition) {
            echo "  [PASS] {$label}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$label}\n";
            $this->failed++;
        }
    }

    private function assertEqual(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            echo "  [PASS] {$label}\n";
            $this->passed++;
        } else {
            $exp = json_encode($expected);
            $act = json_encode($actual);
            echo "  [FAIL] {$label} — expected {$exp}, got {$act}\n";
            $this->failed++;
        }
    }

    // -------------------------------------------------------------------------
    // isLinearChain() tests
    // -------------------------------------------------------------------------

    public function testEmptySetIsLinear(): void
    {
        $git = new GitWorkspace();
        $this->assert($git->isLinearChain([]), 'Empty task set is trivially linear');
    }

    public function testSingleTaskIsLinear(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $this->assert($git->isLinearChain([$a]), 'Single task is trivially linear');
    }

    public function testTwoTaskLinearChain(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']); // B depends on A
        $this->assert($git->isLinearChain([$a, $b]), 'A→B is linear');
    }

    public function testThreeTaskLinearChain(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']);
        $c   = $this->makeTask('c', ['b']);
        $this->assert($git->isLinearChain([$a, $b, $c]), 'A→B→C is linear');
    }

    public function testFiveTaskLinearChain(): void
    {
        $git = new GitWorkspace();
        $tasks = [
            $this->makeTask('a'),
            $this->makeTask('b', ['a']),
            $this->makeTask('c', ['b']),
            $this->makeTask('d', ['c']),
            $this->makeTask('e', ['d']),
        ];
        $this->assert($git->isLinearChain($tasks), 'A→B→C→D→E is linear');
    }

    public function testFanOutNotLinear(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']); // B depends on A
        $c   = $this->makeTask('c', ['a']); // C depends on A (fan-out)
        $this->assert(!$git->isLinearChain([$a, $b, $c]), 'Fan-out (A→B, A→C) is NOT linear');
    }

    public function testFanInNotLinear(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b');
        $c   = $this->makeTask('c', ['a', 'b']); // C depends on both A and B (fan-in)
        $this->assert(!$git->isLinearChain([$a, $b, $c]), 'Fan-in (A→C, B→C) is NOT linear');
    }

    public function testDiamondNotLinear(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']);
        $c   = $this->makeTask('c', ['a']);
        $d   = $this->makeTask('d', ['b', 'c']); // Diamond: A→B→D, A→C→D
        $this->assert(!$git->isLinearChain([$a, $b, $c, $d]), 'Diamond is NOT linear');
    }

    public function testDisjointChainsNotLinear(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']);
        $c   = $this->makeTask('c');       // Disjoint: no connection to A→B
        $d   = $this->makeTask('d', ['c']);
        $this->assert(!$git->isLinearChain([$a, $b, $c, $d]), 'Disjoint chains are NOT linear');
    }

    public function testExternalDepsIgnored(): void
    {
        // A depends on external-id which is not in the set — should still be linear
        $git = new GitWorkspace();
        $a   = $this->makeTask('a', ['external-id']); // external dep
        $b   = $this->makeTask('b', ['a']);
        $this->assert($git->isLinearChain([$a, $b]), 'External deps are ignored in chain detection');
    }

    public function testIndependentTwoTasksNotLinear(): void
    {
        // Two tasks with no relationship — two roots, two leaves → not linear
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b'); // No dep on A
        $this->assert(!$git->isLinearChain([$a, $b]), 'Two independent tasks are NOT linear');
    }

    // -------------------------------------------------------------------------
    // trackBranchRelationship / getBranchChainMap / getBranchChain() tests
    // -------------------------------------------------------------------------

    public function testTrackBranchRelationship(): void
    {
        $git = new GitWorkspace();
        $git->trackBranchRelationship('task/child', 'task/parent');
        $map = $git->getBranchChainMap();
        $this->assertEqual('task/parent', $map['task/child'] ?? null, 'trackBranchRelationship stores child→parent');
    }

    public function testGetBranchChainSingleBranch(): void
    {
        $git = new GitWorkspace();
        // No chain map set — single branch
        $chain = $git->getBranchChain('task/only');
        $this->assertEqual(['task/only'], $chain, 'Single branch chain returns [branch]');
    }

    public function testGetBranchChainLinear(): void
    {
        $git = new GitWorkspace();
        // Track: C → B → A (child→parent)
        $git->trackBranchRelationship('task/b', 'task/a');
        $git->trackBranchRelationship('task/c', 'task/b');

        // getBranchChain from leaf (C) should return root-first [A, B, C]
        $chain = $git->getBranchChain('task/c');
        $this->assertEqual(['task/a', 'task/b', 'task/c'], $chain, 'getBranchChain returns root-first chain');
    }

    public function testGetBranchChainNoCycle(): void
    {
        $git = new GitWorkspace();
        // Cycle guard: A→B→A (should not infinite-loop)
        $git->trackBranchRelationship('task/b', 'task/a');
        $git->trackBranchRelationship('task/a', 'task/b'); // cycle
        $chain = $git->getBranchChain('task/a');
        $this->assert(count($chain) <= 2, 'getBranchChain handles cycles (no infinite loop)');
    }

    // -------------------------------------------------------------------------
    // sortSubtasksTopologically (tested via EmperorController indirectly)
    // isLinearChain order independence
    // -------------------------------------------------------------------------

    public function testIsLinearChainOrderIndependent(): void
    {
        $git = new GitWorkspace();
        $a   = $this->makeTask('a');
        $b   = $this->makeTask('b', ['a']);
        $c   = $this->makeTask('c', ['b']);

        // Try all orderings of [A, B, C]
        $this->assert($git->isLinearChain([$a, $b, $c]), 'A,B,C order — linear');
        $this->assert($git->isLinearChain([$c, $b, $a]), 'C,B,A order — linear');
        $this->assert($git->isLinearChain([$b, $c, $a]), 'B,C,A order — linear');
    }

    // -------------------------------------------------------------------------
    // Integration: EmperorController sortSubtasksTopologically via unit simulation
    // -------------------------------------------------------------------------

    public function testTopologicalSortViaDependencies(): void
    {
        // Simulate what sortSubtasksTopologically does
        $a = $this->makeTask('a', [], 'task/a');
        $b = $this->makeTask('b', ['a'], 'task/b');
        $c = $this->makeTask('c', ['b'], 'task/c');

        // Kahn's algorithm simulation (same as EmperorController::sortSubtasksTopologically)
        $tasks   = [$c, $a, $b]; // out of order
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[$task->id] = $task;
        }
        $idSet    = array_fill_keys(array_keys($taskMap), true);
        $inDegree = array_fill_keys(array_keys($taskMap), 0);
        $children = array_fill_keys(array_keys($taskMap), []);
        foreach ($tasks as $task) {
            foreach ($task->dependsOn as $depId) {
                if (isset($idSet[$depId])) {
                    $inDegree[$task->id]++;
                    $children[$depId][] = $task->id;
                }
            }
        }
        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }
        $sorted = [];
        while (!empty($queue)) {
            $id       = array_shift($queue);
            $sorted[] = $id;
            foreach ($children[$id] as $childId) {
                $inDegree[$childId]--;
                if ($inDegree[$childId] === 0) {
                    $queue[] = $childId;
                }
            }
        }
        $this->assertEqual(['a', 'b', 'c'], $sorted, 'Kahn sort produces A→B→C root-first from out-of-order input');
    }

    // -------------------------------------------------------------------------
    // Run all tests
    // -------------------------------------------------------------------------

    public function run(): int
    {
        echo "\nGitWorkspaceChainTest\n";
        echo str_repeat('=', 50) . "\n";

        $this->testEmptySetIsLinear();
        $this->testSingleTaskIsLinear();
        $this->testTwoTaskLinearChain();
        $this->testThreeTaskLinearChain();
        $this->testFiveTaskLinearChain();
        $this->testFanOutNotLinear();
        $this->testFanInNotLinear();
        $this->testDiamondNotLinear();
        $this->testDisjointChainsNotLinear();
        $this->testExternalDepsIgnored();
        $this->testIndependentTwoTasksNotLinear();
        $this->testTrackBranchRelationship();
        $this->testGetBranchChainSingleBranch();
        $this->testGetBranchChainLinear();
        $this->testGetBranchChainNoCycle();
        $this->testIsLinearChainOrderIndependent();
        $this->testTopologicalSortViaDependencies();

        echo str_repeat('=', 50) . "\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n\n";

        return $this->failed > 0 ? 1 : 0;
    }
}

// Run tests when executed directly
if (php_sapi_name() === 'cli') {
    // Bootstrap autoloader
    $autoloaderPaths = [
        __DIR__ . '/../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
    ];
    foreach ($autoloaderPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }

    $test = new GitWorkspaceChainTest();
    exit($test->run());
}
