<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_recipe\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Confirms signed-out visitors are sent to log in rather than denied.
 */
#[Group('fresco_recipe')]
#[RunTestsInSeparateProcesses]
final class AnonymousAccessDeniedTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'fresco_ai',
    'fresco_recipe',
    'node',
    'system',
    'user',
  ];

  /**
   * Anonymous visitors log in and land back on the bookmarked page.
   */
  public function testAnonymousVisitorIsSentToLogin(): void {
    $this->drupalGet('/generator', ['query' => ['mode' => 'restaurant']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/login');
    $this->assertSession()->fieldExists('name');

    $account = $this->drupalCreateUser(['generate fresco recipes']);
    $this->submitForm([
      'name' => $account->getAccountName(),
      'pass' => $account->passRaw,
    ], 'Log in');

    $this->assertSession()->addressEquals('/generator');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Signed-in users without permission still see access denied.
   */
  public function testSignedInUserWithoutPermissionIsDenied(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet('/admin/config');

    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->addressEquals('/admin/config');
  }

}
