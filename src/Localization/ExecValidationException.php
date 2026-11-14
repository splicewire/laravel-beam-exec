<?php

namespace Splicewire\Beam\Exec\Localization;

use Illuminate\Validation\ClosureValidationRule;
use Splicewire\Beam\Localization\CodedValidationException;

/** Keeps the completed validator, its aggregation and summary; only code metadata is added. */
class ExecValidationException extends CodedValidationException
{
    public function __construct($validator, $response = null, $errorBag = 'default')
    {
        parent::__construct($validator, $response, $errorBag);

        $failed = $validator->failed();
        foreach ($this->errors() as $field => $messages) {
            $rules = array_keys($failed[$field] ?? []);
            $codes = array_map(fn (string $rule): string => self::ruleCode($field, $rule), $rules);
            // Laravel may deduplicate messages, or an after-hook may append one without a failed rule.
            // Keep the original error list untouched; unmatched messages get the generic input identity.
            $this->codes[$field] = array_slice(array_pad($codes, count($messages), 'exec_input_invalid'), 0, count($messages));
        }
    }

    private static function ruleCode(string $field, string $rule): string
    {
        $field = preg_replace('/^members\\.\\d+\\./', 'members.*.', $field);
        if ($rule === ClosureValidationRule::class) {
            return match ($field) {
                'lens' => 'board_lens_invalid',
                'id' => 'scenario_id_invalid',
                default => 'exec_input_invalid',
            };
        }

        $prefix = match ($field) {
            'lens' => 'board_lens',
            'id' => 'scenario_id',
            'label' => 'scenario_label',
            'members' => 'scenario_members',
            'members.*.blueprint' => 'scenario_blueprint',
            'members.*.count' => 'scenario_member_count',
            'members.*.startOffsetMonths' => 'scenario_member_start_offset',
            'churnRate' => 'scenario_churn_rate',
            'expansionRate' => 'scenario_expansion_rate',
            default => null,
        };
        if ($prefix === null) {
            return 'exec_input_invalid';
        }

        $allowed = match ($field) {
            'lens', 'label' => ['Required', 'String', 'Max'],
            'id' => ['Required', 'String', 'Max', 'Regex'],
            'members' => ['Required', 'Array', 'Min', 'Max'],
            'members.*.blueprint' => ['Required', 'String', 'In'],
            'members.*.count', 'members.*.startOffsetMonths' => ['Integer', 'Min', 'Max'],
            'churnRate', 'expansionRate' => ['Numeric', 'Min', 'Max'],
            default => [],
        };
        if (! in_array($rule, $allowed, true)) {
            return 'exec_input_invalid';
        }

        return match ($rule) {
            'Required' => $prefix.'_required',
            'String' => $prefix.'_string',
            'Max' => $prefix.'_max',
            'Min' => $prefix.'_min',
            'Array' => $prefix.'_array',
            'Integer' => $prefix.'_integer',
            'Numeric' => $prefix.'_numeric',
            'Regex' => $field === 'id' ? 'scenario_id_format' : 'exec_input_invalid',
            'In' => $field === 'members.*.blueprint' ? 'scenario_blueprint_unknown' : 'exec_input_invalid',
            default => 'exec_input_invalid',
        };
    }
}
