<?php

declare(strict_types=1);

namespace OCA\MetaVox\Middleware;

use OCA\MetaVox\Exception\AiAutofillException;
use OCA\MetaVox\Exception\ClientErrorException;
use OCA\MetaVox\Service\ApiValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Turns exceptions from MetaVox controllers into JSON errors. Our own
 * exceptions keep their message; anything else is logged and answered with a
 * generic 500, like Nextcloud does (details only in debug mode).
 */
class ExceptionMiddleware extends Middleware {

    public function __construct(
        private readonly IRequest $request,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l,
    ) {
    }

    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        // Pages keep Nextcloud's error page; OCSExceptions are handled by core.
        if ($exception instanceof OCSException || $this->returnsPage($controller, $methodName)) {
            throw $exception;
        }

        // A translated message for people, a fixed reason code for integrations.
        if ($exception instanceof ClientErrorException) {
            $body = ['error' => $this->l->t($exception->getMessage()), 'reason' => $exception->getReason()];
            return $this->respond($controller, $body + $exception->getData(), $exception->getStatus());
        }
        if ($exception instanceof ApiValidationException) {
            return $this->respond($controller, [
                // TRANSLATORS Error message shown when the entered values are not valid
                'error' => $this->l->t('Validation failed'),
                'reason' => 'invalid_input',
                'fields' => array_map(fn ($message) => is_string($message) ? $this->l->t($message) : $message, $exception->getErrors()),
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($exception instanceof AiAutofillException) {
            return $this->respond($controller, ['error' => $this->l->t($exception->getMessage()), 'reason' => 'ai_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $this->logger->error('MetaVox: ' . get_class($controller) . '::' . $methodName . ' failed', [
            'app' => 'metavox',
            'exception' => $exception,
        ]);
        // TRANSLATORS Error message shown when the server failed for an unknown reason
        $body = ['error' => $this->l->t('Internal server error'), 'reason' => 'internal_error', 'requestId' => $this->request->getId()];
        if ($this->config->getSystemValueBool('debug', false)) {
            $body['message'] = $exception->getMessage();
        }
        return $this->respond($controller, $body, Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    private function respond(Controller $controller, array $body, int $status): Response {
        if (!$controller instanceof OCSController) {
            return new JSONResponse($body, $status);
        }
        // An exception skips the dispatcher's OCS formatting, so do it here the
        // same way (getFormat() and isResponderRegistered() are NC 34+).
        $data = new DataResponse($body, $status);
        $format = $this->request->getParam('format')
            ?? $controller->getResponderByHTTPHeader($this->request->getHeader('Accept'), null);
        try {
            return $format !== null ? $controller->buildResponse($data, $format) : $controller->buildResponse($data);
        } catch (\DomainException) {
            // Unknown format.
            return $controller->buildResponse($data);
        }
    }

    private function returnsPage(Controller $controller, string $methodName): bool {
        try {
            $type = (new \ReflectionMethod($controller, $methodName))->getReturnType();
        } catch (\ReflectionException) {
            return false;
        }
        return $type instanceof \ReflectionNamedType && is_a($type->getName(), TemplateResponse::class, true);
    }
}
