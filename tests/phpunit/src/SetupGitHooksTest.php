<?php

namespace Acquia\Blt\Tests;

use Symfony\Component\Process\Process;

/**
 * Class GitTasksTest.
 *
 * @group orca_ignore
 */
class SetupGitHooksTest extends BltProjectTestBase {

  /**
   * Tests setup of git hooks via blt:init:git-hooks command.
   *
   * @dataProvider disabledHooksProvider
   */
  public function testGitHookSetup($disabled_hooks) {
    $this->assertFileExists($this->sandboxInstance . '/.git');

    $this->disableGitHooks($disabled_hooks);

    $this->blt("blt:init:git-hooks");

    $hooks = $this->config->get('git.hooks');
    $this->assertGitHookSetupValidity($hooks, $disabled_hooks);
  }

  /**
   * Tests removal of disabled git hooks via blt:init:git-hooks command.
   *
   * @dataProvider disabledHooksProvider
   */
  public function testDisabledGitHookRemoval($disabled_hooks) {
    $this->assertFileExists($this->sandboxInstance . '/.git');

    $this->blt("blt:init:git-hooks");
    $hooks = $this->config->get('git.hooks');

    $this->disableGitHooks($disabled_hooks);

    $this->blt("blt:init:git-hooks");
    $this->assertGitHookSetupValidity($hooks, $disabled_hooks);
  }

  /**
   * Data provider.
   */
  public function disabledHooksProvider() {
    return [
      [[]],
      [['pre-commit']],
      [['commit-msg']],
      [['pre-commit', 'commit-msg']],
    ];
  }

  /**
   * Tests operation of scripts/git-hooks/commit-msg.
   *
   * @param bool $is_valid
   *   Whether the message is valid.
   * @param string $commit_message
   *   The git commit message.
   * @param string $message
   *   The PHPUnit message to be output for this datapoint.
   *
   * @dataProvider providerTestGitHookCommitMsg
   */
  public function testGitHookCommitMsg($is_valid, $commit_message, $message = NULL) {
    $prefix = $this->config->get('project.prefix');
    $commit_message = str_replace('[prefix]', $prefix, $commit_message);
    $this->assertCommitMessageValidity($is_valid, $commit_message, $message);
  }

  /**
   * Data provider.
   */
  public function providerTestGitHookCommitMsg() {
    return [
      [FALSE, "This is a bad commit.", 'Missing prefix and ticket number.'],
      [FALSE, "123: This is a bad commit.", 'Missing project prefix.'],
      [FALSE, "[prefix]: This is a bad commit.", 'Missing ticket number.'],
      [FALSE, "[prefix]-123 This is a bad commit.", 'Missing colon.'],
      [FALSE, "[prefix]-123: This is a bad commit", 'Missing period.'],
      [FALSE, "[prefix]-123: Hello.", 'Too short.'],
      [FALSE, "NOT-123: This is a bad commit.", 'Wrong project prefix.'],
      [TRUE, "Merge branch 'feature/test'", 'Good commit.'],
      [TRUE, "[prefix]-123: This is a good commit.", 'Good commit.'],
      [TRUE, "[prefix]-123: This is an exceptionally long--seriously, really, really, REALLY long, but still good commit.", 'Long good commit.',
      ],
    ];
  }

  /**
   * Tests operation of scripts/git-hooks/pre-commit.
   *
   * Should assert that code validation via phpcs is functioning.
   */
  public function testGitPreCommitHook() {
    $this->blt("blt:init:git-hooks");
    // Commits must be executed inside of new project directory.
    $process = new Process("./.git/hooks/pre-commit", $this->sandboxInstance);
    $process->run();
    $output = $process->getOutput();
    $this->assertStringContainsString('tests:phpcs:sniff:staged', $output);
    $this->assertStringContainsString('validate:yaml:lint:files', $output);
    $this->assertStringContainsString('validate:twig:lint:files', $output);
  }

  /**
   * Disables a given list of git hooks.
   *
   * @param array $hooks
   *   Hooks.
   */
  protected function disableGitHooks(array $hooks) {
    foreach ($hooks as $hook) {
      $this->config->set("git.hooks.{$hook}", FALSE);
    }
  }

  /**
   * Asserts that the given hooks were setup in a valid manner.
   *
   * @param array $hooks
   *   The possible git hooks provided by BLT.
   * @param array $disabled_hooks
   *   The disabled git hooks.
   */
  protected function assertGitHookSetupValidity(array $hooks, array $disabled_hooks) {
    foreach ($hooks as $hook => $path) {
      $project_hook = $this->sandboxInstance . '/.git/hooks' . "/$hook";
      if (array_key_exists($hook, $disabled_hooks)) {
        $this->assertFileDoesNotExist($project_hook, "Failed asserting that the disabled {$hook} hook was not setup.");
      }
      else {
        $this->assertFileExists($project_hook, "Failed asserting that the enabled {$hook} hook was setup.");
        $source_hook = readlink($project_hook);
        $this->assertFileExists($source_hook, "Failed asserting that the enabled {$hook} hook was setup properly.");
      }
    }
  }

  /**
   * Asserts that a given commit message is valid or not.
   *
   * @param bool $is_valid
   *   Whether the message is valid.
   * @param string $commit_message
   *   The git commit message.
   * @param string $message
   *   The PHPUnit message to be output for this datapoint.
   */
  protected function assertCommitMessageValidity($is_valid, $commit_message, $message = '') {
    // "2>&1" redirects standard error output to standard output.
    $command = "mkdir -p {$this->sandboxInstance}/tmp && echo '$commit_message' > {$this->sandboxInstance}/tmp/blt_commit_msg && {$this->sandboxInstance}/.git/hooks/commit-msg {$this->sandboxInstance}/tmp/blt_commit_msg 2>&1";

    $process = new Process($command, $this->sandboxInstance);
    $process->run();
    $valid_word = $is_valid ? 'valid' : 'invalid';
    $this->assertNotSame($is_valid, $process->getExitCode(), "Failed asserting that commit message \"$commit_message\" is $valid_word. Testing purpose was: $message");
  }

}
