<?php

declare(strict_types=1);

namespace Drupal\Tests\fresco_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\fresco_ai\Plugin\FrescoAiProvider\LocalProvider;
use Drupal\fresco_ai\Service\AiRateLimiter;
use Drupal\fresco_ai\Service\AiUsageLedger;
use Drupal\fresco_ai\Service\GenerationCoordinator;
use Drupal\fresco_ai\Service\RecipeDraftCreator;
use Drupal\fresco_recipe\Validation\RecipeValidator;
use Drupal\fresco_recipe\Validation\ValidationResult;
use Drupal\fresco_recipe\Validation\ValidationRuleInterface;
use Drupal\fresco_recipe\Value\RecipeContract;
use Drupal\fresco_recipe\Value\RecipeContractFactory;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests generating and saving recipe drafts.
 */
#[Group('fresco_ai')]
final class RecipeDraftCreatorTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Confirms a saved draft reports its node and the vegan swaps made.
   */
  public function testCreatesDraftAndReportsSwaps(): void {
    $saved = NULL;
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('create')->willReturnCallback(static function (array $values) use (&$saved, $node): NodeInterface {
      $saved = $values;
      return $node;
    });
    $node->expects(self::once())->method('save');

    $outcome = $this->creator(new RecipeValidator([]), $storage)->create([
      'ingredients' => ['1 lb chicken breast', '1 broccoli crown'],
      'appliances' => ['Stovetop'],
    ]);

    self::assertSame(42, $outcome['nid']);
    self::assertSame([], $outcome['feedback']);
    self::assertSame('recipe', $saved['type']);
    self::assertSame(0, $saved['status']);
    $texts = array_map(static fn (array $message): string => strip_tags((string) $message['text']), $outcome['messages']);
    self::assertContains('Made vegan: 1 lb chicken breast → 1 lb extra-firm tofu', $texts);
  }

  /**
   * Confirms a recipe that fails validation returns feedback and no draft.
   */
  public function testValidationFailureReturnsFeedbackWithoutSaving(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::never())->method('create');
    $alwaysFails = new class implements ValidationRuleInterface {

      /**
       * {@inheritdoc}
       */
      public function validate(RecipeContract $recipe): array {
        return [new ValidationResult('test.always', 'Recipe failed a test-only rule.', 'error', 'ingredients', 'Try again.')];
      }

    };

    $outcome = $this->creator(new RecipeValidator([$alwaysFails]), $storage)->create([
      'ingredients' => ['1 cup vegetable stock', '2 scallions'],
      'appliances' => ['Stovetop'],
    ]);

    self::assertNull($outcome['nid']);
    self::assertSame(['Recipe failed a test-only rule. Try again.'], $outcome['feedback']);
    self::assertSame('error', $outcome['messages'][0]['type']);
  }

  /**
   * Builds the draft creator around the deterministic provider.
   */
  private function creator(RecipeValidator $validator, EntityStorageInterface $storage): RecipeDraftCreator {
    $manager = $this->createMock(DefaultPluginManager::class);
    $manager->method('createInstance')->willReturn(new LocalProvider([], 'local', []));
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => [
      'provider' => 'local',
      'request_rate_limit_per_hour' => 20,
      'audit_retention_days' => 30,
    ][$key] ?? NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([]);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(3);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $creator = new RecipeDraftCreator(
      new GenerationCoordinator(
        $manager,
        $configFactory,
        new RecipeContractFactory(),
        $validator,
        new AiRateLimiter($state, $configFactory),
        new AiUsageLedger($state, $configFactory),
        $account,
        $this->createMock(LoggerInterface::class),
      ),
      $validator,
      $entityTypeManager,
      $account,
    );
    $creator->setStringTranslation($this->getStringTranslationStub());
    return $creator;
  }

}
