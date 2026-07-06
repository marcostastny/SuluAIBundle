<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Marcostastny\SuluAIBundle\Controller\Admin\MetaGenerationController;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\OpenAiMetaGenerator;
use Marcostastny\SuluAIBundle\Service\PageContentExtractor;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class MetaGenerationControllerTest extends TestCase
{
    private function setting(): AiSetting
    {
        $setting = new AiSetting();
        $setting->setEnabled(true);
        $setting->setApiUrl('https://api.test/v1');
        $setting->setApiKey('key');
        $setting->setModel('gpt-test');

        return $setting;
    }

    private function controller(
        ?AiSetting $setting,
        ?PageContentExtractor $extractor = null,
        ?OpenAiMetaGenerator $generator = null,
        ?SecurityCheckerInterface $securityChecker = null
    ): MetaGenerationController {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($setting);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new MetaGenerationController(
            $entityManager,
            $extractor ?? $this->createMock(PageContentExtractor::class),
            $generator ?? $this->createMock(OpenAiMetaGenerator::class),
            $securityChecker ?? $this->createMock(SecurityCheckerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        return new Request(request: $payload);
    }

    public function testDeniedWithoutMetaPermission(): void
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);

        $this->controller($this->setting(), null, null, $securityChecker)
            ->postAction($this->request(['id' => 'abc', 'locale' => 'de']));
    }

    public function testMissingIdReturns400(): void
    {
        $response = $this->controller($this->setting())->postAction($this->request(['id' => '-', 'locale' => 'de']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNotConfiguredReturns400(): void
    {
        $response = $this->controller(null)->postAction($this->request(['id' => 'abc', 'locale' => 'de']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPageNotFoundReturns404(): void
    {
        $extractor = $this->createMock(PageContentExtractor::class);
        $extractor->method('extract')->willThrowException(new PageNotFoundException(['uuid' => 'abc', 'locale' => 'de']));

        $response = $this->controller($this->setting(), $extractor)
            ->postAction($this->request(['id' => 'abc', 'locale' => 'de']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeniedForWebspaceUserCannotView(): void
    {
        $extractor = $this->createMock(PageContentExtractor::class);
        $extractor->method('extract')->willReturn(['title' => 'T', 'text' => 'body', 'webspace' => 'kulm']);

        // Permit the global meta context, deny the page's webspace context.
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('checkPermission')->willReturnCallback(
            static function (mixed $context): void {
                if (\is_string($context) && \str_starts_with($context, 'sulu.webspaces.')) {
                    throw new AccessDeniedException();
                }
            }
        );

        $this->expectException(AccessDeniedException::class);

        $this->controller($this->setting(), $extractor, null, $securityChecker)
            ->postAction($this->request(['id' => 'abc', 'locale' => 'de']));
    }

    public function testHappyPathReturnsMeta(): void
    {
        $extractor = $this->createMock(PageContentExtractor::class);
        $extractor->method('extract')->willReturn(['title' => 'T', 'text' => 'body', 'webspace' => 'kulm']);

        $generator = $this->createMock(OpenAiMetaGenerator::class);
        $generator->method('generate')->willReturn(['title' => 'Meta', 'description' => 'Desc', 'keywords' => 'a, b']);

        $response = $this->controller($this->setting(), $extractor, $generator)
            ->postAction($this->request(['id' => 'abc', 'locale' => 'de']));

        $this->assertSame(200, $response->getStatusCode());
        $decoded = \json_decode((string) $response->getContent(), true);
        $this->assertSame('Meta', $decoded['title']);
    }
}
