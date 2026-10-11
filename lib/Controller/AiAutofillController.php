<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\AiAutofillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AiAutofillController extends Controller {

    private AiAutofillService $aiService;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private IL10N $l10n;

    public function __construct(
        string $appName,
        IRequest $request,
        AiAutofillService $aiService,
        IUserSession $userSession,
        LoggerInterface $logger,
        IL10N $l10n
    ) {
        parent::__construct($appName, $request);
        $this->aiService = $aiService;
        $this->userSession = $userSession;
        $this->logger = $logger;
        $this->l10n = $l10n;
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
                // TRANSLATORS Error message shown to the user when the session has ended and the user must log in again
                return new JSONResponse(['error' => $this->l10n->t('Not logged in')], 401);
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
            // Our own messages say what went wrong (see AiAutofillService); any other
            // exception text stays in the log.
            if (in_array($e->getMessage(), ['No AI text generation provider available', 'AI task failed', 'AI task timed out'], true)) {
                return new JSONResponse(['error' => $this->l10n->t($e->getMessage())], 503);
            }
            // TRANSLATORS Error message in the sidebar when AI could not fill in the metadata; AI = artificial intelligence
            return new JSONResponse(['error' => $this->l10n->t('AI generation failed. Please try again.')], 500);
        }
    }
}
