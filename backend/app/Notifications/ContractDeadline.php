<?php

namespace App\Notifications;

use App\Models\Contract;
use Carbon\CarbonImmutable;

/** CTR-04 / NOT-03 : fin de contrat ou de période d'essai à J-30 et J-7. */
class ContractDeadline extends AppNotification
{
    public function __construct(
        public Contract $contract,
        public string $kind, // fin_contrat | fin_periode_essai
        public CarbonImmutable $date,
        public int $daysLeft,
    ) {}

    public function category(): string
    {
        return 'contract_deadline';
    }

    public function title(): string
    {
        $what = $this->kind === 'fin_contrat' ? "Fin de {$this->contract->type}" : "Fin de période d'essai";

        return "{$what} dans {$this->daysLeft} jours — {$this->contract->employee->full_name}";
    }

    public function message(): string
    {
        $name = $this->contract->employee->full_name;
        $date = $this->date->format('d/m/Y');

        return $this->kind === 'fin_contrat'
            ? "Le contrat {$this->contract->type} de {$name} ({$this->contract->reference}) se termine le {$date}. Pensez à le prolonger par avenant ou à préparer la sortie."
            : "La période d'essai de {$name} se termine le {$date}. Pensez à confirmer l'embauche ou à mettre fin à l'essai avant cette date.";
    }

    public function url(): string
    {
        return "/employes/{$this->contract->employee_id}";
    }
}
