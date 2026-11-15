<?php

namespace App\Data\Scheduling;

final readonly class SchedulePolicyFinding
{
    public function __construct(
        public string $ruleId,
        public string $category,
        public string $severity,
        public array $affectedResources,
        public string $explanation,
        public ?string $remediationUrl,
        public string $policyVersion,
    ) {}

    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'category' => $this->category,
            'severity' => $this->severity,
            'affected_resources' => $this->affectedResources,
            'explanation' => $this->explanation,
            'remediation_url' => $this->remediationUrl,
            'policy_version' => $this->policyVersion,
        ];
    }
}
