<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\View\ViewHandlerInterface;
use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Sulu\Component\Rest\AbstractRestController;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AiSettingController extends AbstractRestController implements SecuredControllerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        ViewHandlerInterface $viewHandler,
        ?TokenStorageInterface $tokenStorage = null
    ) {
        parent::__construct($viewHandler, $tokenStorage);
    }

    public function getAction(): Response
    {
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([]);

        return $this->handleView($this->view($setting ?: new AiSetting()));
    }

    public function putAction(Request $request): Response
    {
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([]);
        if (!$setting) {
            $setting = new AiSetting();
            $this->entityManager->persist($setting);
        }

        $data = $request->request->all();
        $setting->setApiUrl($data['apiUrl'] ?? null);
        $setting->setApiKey($data['apiKey'] ?? null);
        $setting->setModel($data['model'] ?? null);
        $setting->setEnabled((bool) ($data['enabled'] ?? false));

        // Only touch the image fields when the payload carries them, so an
        // old-shape PUT (stale admin bundle, or an external script) can't wipe
        // the saved models and style prompt.
        if (\array_key_exists('imageModels', $data)) {
            $imageModels = [];
            foreach ((array) $data['imageModels'] as $model) {
                if (!\is_array($model) || '' === (string) ($model['modelId'] ?? '')) {
                    continue;
                }
                $imageModels[] = [
                    // Sulu's block field requires a "type" on every item; keep it so
                    // the settings form can render the saved models on reload.
                    'type' => 'model',
                    'label' => (string) ($model['label'] ?? $model['modelId']),
                    'modelId' => (string) $model['modelId'],
                    'supportsReference' => (bool) ($model['supportsReference'] ?? false),
                    'maxImages' => \max(1, \min(4, (int) ($model['maxImages'] ?? 1))),
                ];
            }
            $setting->setImageModels($imageModels);
        }
        if (\array_key_exists('imageStylePrompt', $data)) {
            $setting->setImageStylePrompt(
                '' === (string) $data['imageStylePrompt'] ? null : (string) $data['imageStylePrompt']
            );
        }

        $this->entityManager->flush();

        return $this->handleView($this->view($setting));
    }

    public function getSecurityContext(): string
    {
        return AiSetting::SECURITY_CONTEXT;
    }

    public function getLocale(Request $request): ?string
    {
        return $request->query->get('locale');
    }
}
