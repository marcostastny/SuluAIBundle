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
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);

        return $this->handleView($this->view($setting ?: new AiSetting()));
    }

    public function putAction(Request $request): Response
    {
        $setting = $this->entityManager->getRepository(AiSetting::class)->findOneBy([], ['id' => 'ASC']);
        if (!$setting) {
            $setting = new AiSetting();
            $this->entityManager->persist($setting);
        }

        $data = $request->request->all();
        $setting->setApiUrl($data['apiUrl'] ?? null);
        $setting->setModel($data['model'] ?? null);
        $setting->setEnabled((bool) ($data['enabled'] ?? false));

        // The key is write-only: the form never receives it, so an empty submit
        // means "unchanged". Only overwrite when a new non-empty value is sent.
        if (\array_key_exists('apiKey', $data) && '' !== (string) $data['apiKey']) {
            $setting->setApiKey((string) $data['apiKey']);
        }

        // Only touch when the payload carries the field, so an old-shape PUT
        // (stale admin bundle) can't wipe it. Empty means "use the chat model".
        if (\array_key_exists('mediaMetaModel', $data)) {
            $mediaMetaModel = \trim((string) $data['mediaMetaModel']);
            $setting->setMediaMetaModel('' === $mediaMetaModel ? null : $mediaMetaModel);
        }

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

        // Branding fields: only touch when the payload carries them (same
        // old-shape-PUT protection as the image fields). Whitespace-only
        // values are stored as null so prompt assembly can treat them as unset.
        if (\array_key_exists('agentName', $data)) {
            $agentName = \trim((string) $data['agentName']);
            $setting->setAgentName('' === $agentName ? null : $agentName);
        }
        if (\array_key_exists('personality', $data)) {
            $personality = \trim((string) $data['personality']);
            $setting->setPersonality('' === $personality ? null : $personality);
        }
        if (\array_key_exists('customPrompt', $data)) {
            $customPrompt = \trim((string) $data['customPrompt']);
            $setting->setCustomPrompt('' === $customPrompt ? null : $customPrompt);
        }

        if (\array_key_exists('dataQueryTables', $data)) {
            $dataQueryTables = \trim((string) $data['dataQueryTables']);
            $setting->setDataQueryTables('' === $dataQueryTables ? null : $dataQueryTables);
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
