<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\Tests\Pot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Highlight rules on the columns of a view: what the server stores, keeps and refuses. */
class ViewColumnFormatTest extends TestCase {

    private const FIXTURES = __DIR__ . '/../../fixtures/';
    private const DEADLINE = [
        ['op' => 'before', 'value' => ['today' => 0], 'tone' => 'error', 'preset' => 'overdue'],
        ['op' => 'nextDays', 'value' => 7, 'tone' => 'warning', 'label' => 'Binnenkort'],
        ['op' => 'empty', 'tone' => 'info'],
    ];
    private const RULE = [['op' => 'contains', 'value' => 'x', 'tone' => 'info']];

    private static function fixture(string $name): array {
        return json_decode(file_get_contents(self::FIXTURES . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function column(int $id, string $name, ?array $format = null): array {
        $column = ['field_id' => $id, 'field_name' => $name, 'visible' => true];
        if ($format !== null) {
            $column['format'] = $format;
        }
        return $column;
    }

    /** The errors of a refused save, or null when it was stored. */
    private static function refusal(array $columns, array $existing = []): ?array {
        try {
            ViewColumnFormat::normalizeColumns($columns, $existing);
            return null;
        } catch (ApiValidationException $e) {
            return $e->getErrors();
        }
    }

    // --- The shared vocabulary ---

    public function testOperandKindsMatchTheSharedFixture(): void {
        $this->assertSame(self::fixture('value-operators.json')['operandKinds'], ViewColumnFormat::OPERAND_KINDS);
    }

    public function testTonesPresetsAndLimitsMatchTheSharedFixture(): void {
        $shared = self::fixture('value-operators.json');
        $this->assertSame($shared['tones'], ViewColumnFormat::TONES);
        $this->assertSame($shared['presets'], ViewColumnFormat::PRESETS);
        $this->assertSame($shared['limits'], [
            'rules' => ViewColumnFormat::MAX_RULES,
            'list' => ViewColumnFormat::MAX_LIST,
            'text' => ViewColumnFormat::MAX_TEXT,
            'label' => ViewColumnFormat::MAX_LABEL,
            'days' => ViewColumnFormat::MAX_DAYS,
            'bytes' => ViewColumnFormat::MAX_BYTES,
        ]);
    }

    // --- One rule ---

    public static function serverCases(): array {
        $cases = [];
        foreach (self::fixture('value-rules-server.json')['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    #[DataProvider('serverCases')]
    public function testServerCase(array $case): void {
        // Decoded the way Nextcloud decodes a JSON body.
        $rule = json_decode($case['rule'], true, 512, JSON_THROW_ON_ERROR);
        $columns = [self::column(1, 'f', [$rule])];
        if (isset($case['stored'])) {
            $this->assertSame([$case['stored']], ViewColumnFormat::normalizeColumns($columns)[0]['format']);
            return;
        }
        $key = 'columns.0.format.0' . ($case['error'] === 'rule' ? '' : '.' . $case['error']);
        $this->assertSame([$key => $case['message']], self::refusal($columns));
    }

    public static function fixtureRules(): array {
        $rules = [];
        foreach (['value-rules.json', 'value-rules-blind.json'] as $file) {
            foreach (self::fixture($file)['vectors'] as $vector) {
                $rules["$file: " . $vector['name']] = [$vector['rule'] + ['tone' => 'info']];
            }
        }
        return $rules;
    }

    /** Every rule the browser is tested with can be stored, and storing it again changes nothing. */
    #[DataProvider('fixtureRules')]
    public function testFixtureRulesAreAcceptedOnce(array $rule): void {
        $stored = ViewColumnFormat::normalizeColumns([self::column(1, 'f', [$rule])]);
        $this->assertSame($rule['op'], $stored[0]['format'][0]['op']);
        $this->assertSame($stored, ViewColumnFormat::normalizeColumns($stored));
    }

    public function testDeadlineExampleFromThePlan(): void {
        $stored = ViewColumnFormat::normalizeColumns([self::column(12, 'deadline', self::DEADLINE)]);
        $this->assertSame(self::DEADLINE, $stored[0]['format']);
    }

    public function testOperatorTypeIsNotChecked(): void {
        // A date rule on what may now be a text field: the browser skips it.
        $stored = ViewColumnFormat::normalizeColumns([self::column(1, 'notes', [['op' => 'nextDays', 'value' => 7, 'tone' => 'info']])]);
        $this->assertSame('nextDays', $stored[0]['format'][0]['op']);
    }

    // --- The format key ---

    public function testColumnsWithoutFormatAreUnchanged(): void {
        $columns = [
            ['field_id' => 3, 'field_name' => 'status', 'field_label' => 'Status', 'field_type' => 'select', 'field_options' => ['A', 'B'], 'visible' => true, 'filterable' => false],
            ['field_id' => '4', 'field_name' => 'oud', 'show_as_column' => 1, 'column_order' => 2, 'extra' => ['x' => null]],
        ];
        $this->assertSame(json_encode($columns), json_encode(ViewColumnFormat::normalizeColumns($columns)));
    }

    public function testFormatNullIsRefusedEvenWithStoredRules(): void {
        $stored = [self::column(1, 'f', self::RULE)];
        $this->assertSame(['columns.0.format' => ViewColumnFormat::MSG_INVALID], self::refusal([self::column(1, 'f') + ['format' => null]], $stored));
    }

    public function testFormatOfAnotherTypeIsRefused(): void {
        foreach (['x', 5, true, self::RULE[0]] as $format) {
            $this->assertSame(['columns.0.format' => ViewColumnFormat::MSG_INVALID], self::refusal([self::column(1, 'f', null) + ['format' => $format]]), json_encode($format));
        }
    }

    public function testEmptyFormatClearsStoredRules(): void {
        $stored = [self::column(1, 'f', self::RULE)];
        // [] and {} decode the same.
        $saved = ViewColumnFormat::normalizeColumns([self::column(1, 'f', [])], $stored);
        $this->assertArrayNotHasKey('format', $saved[0]);
    }

    public function testTenRulesAreAcceptedElevenRefused(): void {
        $this->assertCount(10, ViewColumnFormat::normalizeColumns([self::column(1, 'f', array_fill(0, 10, self::RULE[0]))])[0]['format']);
        $this->assertSame(['columns.0.format' => ViewColumnFormat::MSG_TOO_MANY], self::refusal([self::column(1, 'f', array_fill(0, 11, self::RULE[0]))]));
    }

    // --- Keeping stored rules ---

    public function testKeepById(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        $this->assertSame(self::RULE, ViewColumnFormat::normalizeColumns([self::column(12, 'status')], $stored)[0]['format']);
    }

    public function testKeepByDigitStringId(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        $saved = ViewColumnFormat::normalizeColumns([['field_id' => '12', 'field_name' => 'other']], $stored);
        $this->assertSame(self::RULE, $saved[0]['format']);
    }

    public function testKeepByNameWhenNoId(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        $this->assertSame(self::RULE, ViewColumnFormat::normalizeColumns([['field_name' => 'status']], $stored)[0]['format']);
    }

    public function testIdZeroFallsBackToName(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        foreach ([0, '0', '00', '', null] as $id) {
            $saved = ViewColumnFormat::normalizeColumns([['field_id' => $id, 'field_name' => 'status']], $stored);
            $this->assertSame(self::RULE, $saved[0]['format'], json_encode($id));
        }
    }

    public function testNameFallbackWhenNoColumnClaimsTheStoredId(): void {
        // The field was made again under a new id; the grid shows it by name.
        $stored = [self::column(12, 'status', self::RULE)];
        $this->assertSame(self::RULE, ViewColumnFormat::normalizeColumns([self::column(99, 'status')], $stored)[0]['format']);
    }

    public function testOwnIdWinsOverAName(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        $saved = ViewColumnFormat::normalizeColumns([self::column(99, 'status'), self::column(12, 'state')], $stored);
        $this->assertArrayNotHasKey('format', $saved[0]);
        $this->assertSame(self::RULE, $saved[1]['format']);
    }

    public function testRulesFollowTheirFieldWhenReordered(): void {
        $other = [['op' => 'empty', 'tone' => 'warning']];
        $stored = [self::column(1, 'a', self::RULE), self::column(2, 'b', $other)];
        $saved = ViewColumnFormat::normalizeColumns([self::column(2, 'b'), self::column(1, 'a')], $stored);
        $this->assertSame($other, $saved[0]['format']);
        $this->assertSame(self::RULE, $saved[1]['format']);
    }

    public function testNewColumnGetsNoRulesAndALeftOutOneLosesThem(): void {
        $stored = [self::column(1, 'a', self::RULE)];
        $saved = ViewColumnFormat::normalizeColumns([self::column(2, 'b')], $stored);
        $this->assertSame([self::column(2, 'b')], $saved);
    }

    public function testKeptRulesAreCopiedAsStoredAndNothingElse(): void {
        // Rules a later version wrote stay as they are; enriched keys are not copied.
        $later = [['op' => 'matches', 'value' => '/x/', 'tone' => 'info']];
        $stored = [self::column(1, 'a', $later) + ['field_type' => 'text', 'field_options' => []]];
        $this->assertSame(self::column(1, 'a', $later), ViewColumnFormat::normalizeColumns([self::column(1, 'a')], $stored)[0]);
    }

    public function testStoredItemsThatAreNoRulesAreSkipped(): void {
        $stored = ['x', null, self::column(1, 'a') + ['format' => 'nope'], self::column(1, 'a') + ['format' => ['op' => 'empty']], self::column(1, 'a', self::RULE)];
        $this->assertSame(self::RULE, ViewColumnFormat::normalizeColumns([self::column(1, 'a')], $stored)[0]['format']);
    }

    public function testFirstStoredMatchWins(): void {
        $other = [['op' => 'empty', 'tone' => 'warning']];
        $stored = [self::column(1, 'a', self::RULE), self::column(1, 'a', $other)];
        $this->assertSame(self::RULE, ViewColumnFormat::normalizeColumns([self::column(1, 'a')], $stored)[0]['format']);
    }

    public function testKeptRulesGoToOneColumnOnly(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        $saved = ViewColumnFormat::normalizeColumns(array_fill(0, 2000, ['field_id' => 12]), $stored);
        $this->assertSame(self::RULE, $saved[0]['format']);
        $this->assertCount(1, array_filter($saved, fn ($c) => isset($c['format'])));

        $byName = ViewColumnFormat::normalizeColumns(array_fill(0, 500, ['field_name' => 'status']), $stored);
        $this->assertCount(1, array_filter($byName, fn ($c) => isset($c['format'])));
    }

    public function testAClearWinsOverADuplicate(): void {
        $stored = [self::column(12, 'status', self::RULE)];
        foreach ([[self::column(12, 'status', []), self::column(12, 'status')], [self::column(12, 'status'), self::column(12, 'status', [])]] as $columns) {
            $saved = ViewColumnFormat::normalizeColumns($columns, $stored);
            $this->assertCount(0, array_filter($saved, fn ($c) => isset($c['format'])));
        }
    }

    // --- Size ---

    /** Columns whose rules take exactly $bytes, measured as the browser measures. */
    private static function columnsOfBytes(int $bytes): array {
        $columns = [];
        for ($id = 1; ViewColumnFormat::formatBytes($columns) < $bytes; $id++) {
            $columns[] = self::column($id, "f$id", array_fill(0, 10, ['op' => 'contains', 'value' => str_repeat('x', 200), 'tone' => 'info']));
        }
        $last = count($columns) - 1;
        while (($over = ViewColumnFormat::formatBytes($columns) - $bytes) > 0) {
            $rule = count($columns[$last]['format']) - 1;
            $length = strlen($columns[$last]['format'][$rule]['value']);
            if ($over < $length) {
                $columns[$last]['format'][$rule]['value'] = str_repeat('x', $length - $over);
            } else {
                array_pop($columns[$last]['format']);
            }
        }
        return $columns;
    }

    public function testSizeBoundary(): void {
        $this->assertNull(self::refusal(self::columnsOfBytes(ViewColumnFormat::MAX_BYTES)));
        $this->assertSame(['columns' => ViewColumnFormat::MSG_SIZE], self::refusal(self::columnsOfBytes(ViewColumnFormat::MAX_BYTES + 1)));
    }

    public function testSizeIsMeasuredUnescaped(): void {
        $columns = [self::column(1, 'f', [['op' => 'contains', 'value' => "é/\u{2028}", 'tone' => 'info']])];
        $this->assertSame(strlen('[[{"op":"contains","value":"' . "é/\u{2028}" . '","tone":"info"}]]'), ViewColumnFormat::formatBytes($columns));
    }

    public function testSizeIsMeasuredAfterNormalising(): void {
        // Padding that trimming removes does not count.
        $columns = self::columnsOfBytes(ViewColumnFormat::MAX_BYTES);
        $columns[0]['format'][0]['value'] = str_repeat(' ', 100) . $columns[0]['format'][0]['value'];
        $this->assertNull(self::refusal($columns));
    }

    public function testKeptRulesCountTowardsTheSize(): void {
        $stored = self::columnsOfBytes(ViewColumnFormat::MAX_BYTES - 50);
        $sent = array_map(fn ($c) => ['field_id' => $c['field_id']], $stored);
        $this->assertNull(self::refusal($sent, $stored));
        $sent[] = self::column(999, 'new', [['op' => 'contains', 'value' => str_repeat('x', 60), 'tone' => 'info']]);
        $this->assertSame(['columns' => ViewColumnFormat::MSG_SIZE], self::refusal($sent, $stored));
    }

    // --- Where an error is reported ---

    public function testErrorKeyUsesTheRequestIndex(): void {
        $columns = array_map(fn ($i) => self::column($i + 1, "f$i"), range(0, 3));
        $columns[3]['format'] = [self::RULE[0], ['op' => 'greater', 'value' => 'x', 'tone' => 'info']];
        $this->assertSame(['columns.3.format.1.value' => ViewColumnFormat::MSG_NUMBER], self::refusal($columns));
    }

    public function testFirstErrorWins(): void {
        $columns = [self::column(1, 'a', [['op' => 'nope', 'tone' => 'x']]), self::column(2, 'b') + ['format' => null]];
        $this->assertSame(['columns.0.format.0.op' => ViewColumnFormat::MSG_INVALID], self::refusal($columns));
    }

    public function testColumnsMustBeAList(): void {
        $this->assertSame(['columns' => ViewColumnFormat::MSG_INVALID], self::refusal(['a' => self::column(1, 'a')]));
        $this->assertSame([], ViewColumnFormat::normalizeColumns([]));
    }

    public function testAColumnMustBeAnObject(): void {
        foreach (['x', 5, null, [], ['a']] as $column) {
            $this->assertSame(['columns.0' => ViewColumnFormat::MSG_INVALID], self::refusal([$column]), json_encode($column));
        }
    }

    /** field_id and field_name are bound in a query on every read; a bad one would break all reads. */
    public function testColumnFieldShapes(): void {
        foreach ([true, [1], 1.5, -1, 'abc', ' 1', 2147483648, '2147483648', '12345678901'] as $id) {
            $this->assertSame(['columns.0.field_id' => ViewColumnFormat::MSG_INVALID], self::refusal([['field_id' => $id]]), json_encode($id));
        }
        foreach ([['x'], 5, "a\0b"] as $name) {
            $this->assertSame(['columns.0.field_name' => ViewColumnFormat::MSG_INVALID], self::refusal([['field_id' => 1, 'field_name' => $name]]), json_encode($name));
        }
        foreach ([12, '12', 0, '0', '00', '', null, 2147483647] as $id) {
            $this->assertNull(self::refusal([['field_id' => $id, 'field_name' => 'x']]), json_encode($id));
        }
        $this->assertNull(self::refusal([['field_id' => 1, 'field_name' => null], ['field_id' => 2]]));
    }

    // --- Text ---

    public function testJsTrim(): void {
        // The 25 code points of String.prototype.trim.
        $spaces = [0x9, 0xA, 0xB, 0xC, 0xD, 0x20, 0xA0, 0x1680, 0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006,
            0x2007, 0x2008, 0x2009, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF];
        $this->assertCount(25, $spaces);
        foreach ($spaces as $cp) {
            $s = mb_chr($cp, 'UTF-8');
            $this->assertSame('a' . $s . 'b', ViewColumnFormat::jsTrim($s . 'a' . $s . 'b' . $s), sprintf('U+%04X', $cp));
        }
        foreach ([0x85, 0x180E, 0x200B] as $cp) {
            $s = mb_chr($cp, 'UTF-8');
            $this->assertSame($s . 'a' . $s, ViewColumnFormat::jsTrim($s . 'a' . $s), sprintf('U+%04X', $cp));
        }
        $this->assertNull(ViewColumnFormat::jsTrim("\xFF"));
    }

    public function testJsTrimIsLinearWithoutJit(): void {
        // A process of its own: this one has the pattern compiled with JIT already.
        $file = (new \ReflectionClass(ViewColumnFormat::class))->getFileName();
        $code = 'require ' . var_export($file, true) . ';'
            . '$s = "x" . str_repeat(" ", 20000) . "y"; $t = microtime(true);'
            . '$r = OCA\MetaVox\Service\ViewColumnFormat::jsTrim($s);'
            . 'echo json_encode([microtime(true) - $t, $r === $s]);';
        exec(escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -r ' . escapeshellarg($code), $out, $status);
        $this->assertSame(0, $status);
        [$seconds, $same] = json_decode(implode('', $out), true);
        $this->assertTrue($same);
        $this->assertLessThan(0.5, $seconds);
    }

    public function testLabelsCountCharactersNotBytes(): void {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 5 code points
        $rule = fn (string $label) => [self::column(1, 'f', [['op' => 'empty', 'tone' => 'info', 'label' => $label]])];
        $this->assertNull(self::refusal($rule(str_repeat('é', 35) . $family)));
        $this->assertSame(['columns.0.format.0.label' => ViewColumnFormat::MSG_LABEL], self::refusal($rule(str_repeat('é', 36) . $family)));
    }

    public function testInvalidUtf8IsRefused(): void {
        $this->assertSame(['columns.0.format.0.value' => ViewColumnFormat::MSG_INVALID], self::refusal([self::column(1, 'f', [['op' => 'is', 'value' => "\xFF", 'tone' => 'info']])]));
        $this->assertSame(['columns.0.format.0.label' => ViewColumnFormat::MSG_INVALID], self::refusal([self::column(1, 'f', [['op' => 'empty', 'tone' => 'info', 'label' => "\xFF"]])]));
    }

    // --- Robustness ---

    /** Whatever comes in, the answer is a stored view or a validation error, never a crash or warning. */
    public function testOnlyValidationErrorsAreThrown(): void {
        $deep = 'x';
        for ($i = 0; $i < 70; $i++) {
            $deep = [$deep];
        }
        $values = [INF, -INF, NAN, 1e19, 1e300, -1e300, "\xFF", $deep, " \t ", 'Goedgekeurd', 5, true, false, null, [], ['me' => true], ['today' => 0]];
        $ops = array_keys(ViewColumnFormat::OPERAND_KINDS);
        mt_srand(1);
        for ($n = 0; $n < 500; $n++) {
            $values[] = self::randomShape(3);
        }
        $count = 0;
        foreach ($values as $value) {
            foreach ($ops as $op) {
                foreach ([['op' => $op, 'value' => $value, 'tone' => 'info', 'label' => $value], $value] as $rule) {
                    try {
                        ViewColumnFormat::normalizeColumns([self::column(1, 'f', [$rule])]);
                    } catch (ApiValidationException) {
                    }
                    $count++;
                }
            }
        }
        $this->assertGreaterThan(18000, $count);
    }

    private static function randomShape(int $depth): mixed {
        $pick = mt_rand(0, $depth > 0 ? 8 : 5);
        return match ($pick) {
            0 => mt_rand(-5, 4000),
            1 => mt_rand() / mt_getrandmax() * 10 ** mt_rand(-3, 320),
            2 => str_repeat(['x', ' ', "\u{3000}", "\xFF", '9', '-', 'é'][mt_rand(0, 6)], mt_rand(0, 250)),
            3 => (bool)mt_rand(0, 1),
            4 => null,
            5 => sprintf('%04d-%02d-%02d', mt_rand(0, 9999), mt_rand(0, 13), mt_rand(0, 32)),
            6 => array_map(fn () => self::randomShape($depth - 1), range(0, mt_rand(0, 3))),
            7 => ['today' => self::randomShape($depth - 1)],
            default => ['me' => self::randomShape($depth - 1), 'x' => 1],
        };
    }

    public function testEveryMessageIsTranslated(): void {
        $messages = array_filter((new \ReflectionClass(ViewColumnFormat::class))->getConstants(), fn ($name) => str_starts_with($name, 'MSG_'), ARRAY_FILTER_USE_KEY);
        $this->assertNotEmpty($messages);
        $msgids = Pot::msgids();
        foreach ($messages as $message) {
            $this->assertArrayHasKey($message, $msgids, $message);
        }
    }
}
