<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\AiAutofillService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AiAutofillController extends Controller {

    use ReadsParams;

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
    #[RequireAccess(Access::LoggedIn, reason: 'only whether AI suggestions are available')]
    public function status(): JSONResponse {
        return new JSONResponse([
            'available' => $this->aiService->isAvailable(),
        ]);
    }

    /**
     * Generate metadata suggestions for a file
     */
    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdate, folder: 'groupfolderId', file: 'fileId')]
    public function generate(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw RequestException::notLoggedIn();
        }

        // Checked by the access rule as well, which needs them to be ids.
        $fileId = $this->intParam('fileId', required: true, min: 1);
        $groupfolderId = $this->intParam('groupfolderId', required: true, min: 1);

        // Field name => the value the user turned down; it goes into the prompt.
        $rejectedSuggestions = $this->mapParam('rejectedSuggestions');
        if (\count($rejectedSuggestions) > 50 || array_filter($rejectedSuggestions,
            fn ($value) => !(is_string($value) || is_int($value) || is_float($value)) || mb_strlen((string)$value) > 1000) !== []) {
            throw new ApiValidationException(['rejectedSuggestions' => ViewColumnFormat::MSG_INVALID]);
        }

        $this->logger->debug('MetaVox: AI generating metadata', ['fileId' => $fileId, 'groupfolderId' => $groupfolderId]);
        $suggestions = $this->aiService->generateMetadata($fileId, $groupfolderId, $user->getUID(), $rejectedSuggestions);
        $this->logger->debug('MetaVox: AI generation complete', ['suggestionCount' => count($suggestions)]);

        return new JSONResponse([
            'suggestions' => $suggestions,
        ]);
    }
}
