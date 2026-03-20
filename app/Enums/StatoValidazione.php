<?php

namespace App\Enums;

enum StatoValidazione: string
{
    case NonVerificato           = 'non_verificato';
    case NonPercorribile         = 'non_percorribile';
    case InRevisioneValidazione  = 'in_revisione_validazione';
    case InPreAccatastamento     = 'in_pre_accatastamento';
    case Percorribile            = 'percorribile';
    case Validato                = 'validato';
    case Certificato             = 'certificato';

    public static function fromApiId(string $id): ?self
    {
        return match ($id) {
            '4871' => self::NonVerificato,
            '4870' => self::NonPercorribile,
            '4869' => self::InRevisioneValidazione,
            '4868' => self::InPreAccatastamento,
            '4187' => self::Percorribile,
            '4188' => self::Validato,
            '4189' => self::Certificato,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NonVerificato          => 'Non verificato',
            self::NonPercorribile        => 'Non percorribile',
            self::InRevisioneValidazione => 'In revisione-validazione',
            self::InPreAccatastamento    => 'In pre-accatastamento o manutenzione',
            self::Percorribile           => 'Percorribile',
            self::Validato               => 'Validato',
            self::Certificato            => 'Certificato',
        };
    }
}
