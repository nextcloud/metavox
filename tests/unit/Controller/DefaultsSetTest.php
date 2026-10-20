<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\DefaultsController;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\DefaultsDiscovery;
use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Validation\LinkSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** A default takes what its field takes, as a value does. */
class DefaultsSetTest extends TestCase {

    private array $params = [];
    private array $field = ['id' => 3, 'field_name' => 'file_gf_count', 'field_type' => 'number'];
    /** @var list<?string> what was stored as the default */
    private array $stored = [];
    private string $normalized = '';

    private function controller(): DefaultsController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->params) ? $this->params[$key] : $default);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->createMock(IUser::class));
        $fields = $this->createMock(FieldService::class);
        $fields->method('getFieldById')->willReturnCallback(fn (int $id) => $id === 3 ? $this->field : null);
        $defaults = $this->createMock(DefaultsService::class);
        $defaults->method('setFieldDefault')->willReturnCallback(function (int $gf, int $fieldId, ?string $value): bool {
            $this->stored[] = $value;
            return true;
        });
        $links = $this->createMock(FileReferenceService::class);
        $links->method('normalize')->willReturnCallback(fn () => $this->normalized);
        return new DefaultsController('metavox', $request, $fields, $session, $defaults, $links,
            $this->createMock(IJobList::class), $this->createMock(DefaultsDiscovery::class), $this->createMock(ITimeFactory::class),
            new FieldValue($this->createMock(AccountService::class), $this->createMock(LinkSettings::class)));
    }

    private function refusal(): array {
        try {
            $this->controller()->setDefault(7);
        } catch (ApiValidationException $e) {
            return $e->getErrors();
        }
        $this->fail('The default was accepted');
    }

    public function testADefaultIsStoredInTheFormItsFieldStoresIt(): void {
        $this->params = ['fieldId' => 3, 'value' => ' 12 '];
        $this->controller()->setDefault(7);
        $this->assertSame(['12'], $this->stored);
    }

    public function testADefaultItsFieldDoesNotTakeIsRefused(): void {
        $this->params = ['fieldId' => 3, 'value' => 'abc'];
        $this->assertSame(['value' => 'Enter a number, such as 12 or 3.5'], $this->refusal());
        $this->assertSame([], $this->stored);
    }

    public function testNoValueIsNoDefault(): void {
        foreach (['', null] as $value) {
            $this->params = ['fieldId' => 3, 'value' => $value];
            $this->controller()->setDefault(7);
        }
        $this->assertSame([null, null], $this->stored);
    }

    public function testAFileLinkDefaultPointsAtAFileInTheTeamFolder(): void {
        $this->field = ['id' => 3, 'field_name' => 'file_gf_contract', 'field_type' => 'filelink'];
        $this->params = ['fieldId' => 3, 'value' => '/HR/contract.pdf;#/Elsewhere/x.pdf'];

        $this->normalized = '';
        $this->assertSame(['value' => 'Link to a file in this Team folder'], $this->refusal(), 'both outside');
        $this->normalized = 'Elsewhere/x.pdf';
        $this->assertSame(['value' => 'Link to a file in this Team folder'], $this->refusal(), 'pointing at nothing');

        // A link outside the team folder is left out, as on every value.
        $this->normalized = '12:';
        $this->controller()->setDefault(7);
        $this->assertSame(['12:'], $this->stored);
    }
}
