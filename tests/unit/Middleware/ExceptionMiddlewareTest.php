<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Middleware;

use OCA\MetaVox\Exception\AiAutofillException;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Middleware\ExceptionMiddleware;
use OCA\MetaVox\Service\ApiValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExceptionMiddlewareTest extends TestCase {

    private const SQL_ERROR = "SQLSTATE[42S22]: Unknown column 'field_value' in oc_metavox_file_gf_meta";

    private IRequest&MockObject $request;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;

    private ?string $format = null;

    /** @var list<list<mixed>> arguments of each buildResponse() call */
    private array $built = [];

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getId')->willReturn('req-123');
        $this->request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'format' ? $this->format : null);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function middleware(): ExceptionMiddleware {
        // Marks what went through the translation.
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text) => "[nl] $text");
        return new ExceptionMiddleware($this->request, $this->config, $this->logger, $l);
    }

    private function webController(): Controller {
        return new class('metavox', $this->request) extends Controller {
            public function data(): JSONResponse {
                return new JSONResponse();
            }

            public function page(): TemplateResponse {
                throw new \LogicException('not called');
            }
        };
    }

    /** The OCS envelope classes are private to NC, so buildResponse() is recorded instead. */
    private function ocsController(): OCSController {
        $controller = $this->createMock(OCSController::class);
        $controller->method('getResponderByHTTPHeader')->willReturnCallback(fn ($accept, $default) => $default);
        $controller->method('buildResponse')->willReturnCallback(function (...$args) {
            if (($args[1] ?? null) === 'html') {
                throw new \DomainException('No responder registered for format html!');
            }
            $this->built[] = $args;
            return new Response();
        });
        return $controller;
    }

    public function testAccessErrorsAreTranslatedWithAReason(): void {
        $response = $this->middleware()->afterException($this->webController(), 'data', MetadataAccessException::readOnly());

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['error' => '[nl] No permission to change the metadata of this file', 'reason' => 'read_only'], $response->getData());
    }

    public function testANonMemberGetsTheSameAnswerAsForSomethingThatIsNotThere(): void {
        $response = $this->middleware()->afterException($this->webController(), 'data', MetadataAccessException::notMember());

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('not_found', $response->getData()['reason']);
    }

    public function testOcsErrorsGetTheOcsEnvelope(): void {
        $this->format = 'json';

        $this->middleware()->afterException($this->ocsController(), 'data', MetadataAccessException::notFound());

        [$data, $format] = $this->built[0];
        $this->assertInstanceOf(DataResponse::class, $data);
        $this->assertSame(Http::STATUS_NOT_FOUND, $data->getStatus());
        $this->assertSame(['error' => '[nl] File not found', 'reason' => 'not_found'], $data->getData());
        $this->assertSame('json', $format);
    }

    public function testOcsDefaultsToXml(): void {
        $this->middleware()->afterException($this->ocsController(), 'data', MetadataAccessException::notFound());

        $this->assertSame('xml', $this->built[0][1]);
    }

    public function testOcsFallsBackToXmlOnAnUnknownFormat(): void {
        $this->format = 'html';

        $this->middleware()->afterException($this->ocsController(), 'data', MetadataAccessException::notFound());

        $this->assertSame('xml', $this->built[0][1]);
    }

    public function testValidationErrorsAreBadRequests(): void {
        $exception = new ApiValidationException(['field_name' => 'A field with this name already exists']);

        $response = $this->middleware()->afterException($this->webController(), 'data', $exception);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([
            'error' => '[nl] Validation failed',
            'reason' => 'invalid_input',
            'fields' => ['field_name' => '[nl] A field with this name already exists'],
        ], $response->getData());
    }

    public function testAiErrorsKeepTheirMessage(): void {
        $response = $this->middleware()->afterException($this->webController(), 'data', new AiAutofillException('AI task timed out'));

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame(['error' => '[nl] AI task timed out', 'reason' => 'ai_unavailable'], $response->getData());
    }

    public function testOtherErrorsAreLoggedAndHidden(): void {
        $exception = new \RuntimeException(self::SQL_ERROR);
        $this->logger->expects($this->once())->method('error')
            ->with($this->anything(), $this->callback(fn (array $context) => $context['app'] === 'metavox'
                && $context['exception'] === $exception));

        $response = $this->middleware()->afterException($this->webController(), 'data', $exception);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame(['error' => '[nl] Internal server error', 'reason' => 'internal_error', 'requestId' => 'req-123'], $response->getData());
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
    }

    public function testDebugModeShowsTheMessage(): void {
        $this->config->method('getSystemValueBool')->with('debug', false)->willReturn(true);

        $response = $this->middleware()->afterException($this->webController(), 'data', new \RuntimeException(self::SQL_ERROR));

        $this->assertSame(self::SQL_ERROR, $response->getData()['message']);
    }

    public function testPagesKeepNextcloudsErrorPage(): void {
        $exception = new \RuntimeException(self::SQL_ERROR);

        $this->expectExceptionObject($exception);
        $this->middleware()->afterException($this->webController(), 'page', $exception);
    }

    public function testOcsExceptionsAreLeftToCore(): void {
        $exception = new OCSNotFoundException('Not found');

        $this->expectExceptionObject($exception);
        $this->middleware()->afterException($this->ocsController(), 'data', $exception);
    }
}
