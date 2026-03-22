<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\AiAutofillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AiAutofillController extends Controller {

    private AiAutofillService $aiService;
    private IUserSession $userSession;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        AiAutofillService $aiService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->aiService = $aiService;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Check if AI is available
     */
    #[NoAdminRequired]
    public function status(): JSONResponse {
        return new JSONResponse([
            'available' => $this->aiService->isAvailable(),
        ]);
    }

    /**
     * Generate metadata suggestions for a file
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'User not authenticated'], 401);
            }

            $fileId = (int)$this->request->getParam('fileId');
            $groupfolderId = (int)$this->request->getParam('groupfolderId');

            if (!$fileId || !$groupfolderId) {
                return new JSONResponse(['error' => 'fileId and groupfolderId are required'], 400);
            }

            $rejectedSuggestions = $this->request->getParam('rejectedSuggestions', []);

            $this->logger->debug('MetaVox: AI generating metadata', ['fileId' => $fileId, 'groupfolderId' => $groupfolderId]);
            $suggestions = $this->aiService->generateMetadata($fileId, $groupfolderId, $user->getUID(), $rejectedSuggestions);
            $this->logger->debug('MetaVox: AI generation complete', ['suggestionCount' => count($suggestions)]);

            return new JSONResponse([
                'suggestions' => $suggestions,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: AI autofill error', ['exception' => $e]);
            return new JSONResponse([
                'error' => 'AI generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
