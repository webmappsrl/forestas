<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoEnte: string
{
    case ComplessoForestale = 'complesso-forestale';
    case EntePartner = 'ente-partner';
    case AltrePubbliche = 'altre-pubbliche-istituzioni';
    case PrivatoAssociazione = 'privato-associazione';
    case Comune = 'comune';

    public function getDrupalId(): int
    {
        return match ($this) {
            self::ComplessoForestale => 4699,
            self::EntePartner => 4700,
            self::AltrePubbliche => 4701,
            self::PrivatoAssociazione => 4702,
            self::Comune => 4703,
        };
    }

    public static function fromDrupalId(int $id): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->getDrupalId() === $id) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::ComplessoForestale => __('Forest complex'),
            self::EntePartner => __('Partner body'),
            self::AltrePubbliche => __('Other public institutions'),
            self::PrivatoAssociazione => __('Private/association'),
            self::Comune => __('Municipality'),
        };
    }
}
