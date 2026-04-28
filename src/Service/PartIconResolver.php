<?php

declare(strict_types=1);

namespace App\Service;

final class PartIconResolver
{
    private const PART_ICON_MAP = [
        'warframe' => [
            'neuroptics' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_helmet_128x128.png?v=2',
            'chassis' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_chassis_128x128.png?v=2',
            'systems' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_systems_128x128.png?v=2',
            'ornament' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_ornament_128x128.png?v=2',
            'blueprint' => 'https://warframe.market/static/assets/sub_icons/blueprint_128x128.png?v=2',
        ],
        'weapon' => [
            'boot' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_boot_128x128.png?v=2',
            'gauntlet' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_gauntlet_128x128.png?v=2',
            'barrel' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_barrel_128x128.png?v=2',
            'receiver' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_receiver_128x128.png?v=2',
            'reciever' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_receiver_128x128.png?v=2',
            'stock' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_stock_128x128.png?v=2',
            'link' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_link_128x128.png?v=2',
            'limb' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_limb_128x128.png?v=2',
            'string' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_string_128x128.png?v=2',
            'grip' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_grip_128x128.png?v=2',
            'blade' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_blade_128x128.png?v=2',
            'handle' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_handle_128x128.png?v=2',
            'hilt' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_handle_128x128.png?v=2',
            'disc' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_disc_128x128.png?v=2',
            'guard' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_guard_128x128.png?v=2',
            'head' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_head_128x128.png?v=2',
            'stars' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_stars_128x128.png?v=2',
            'pouch' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_holster_128x128.png?v=2',
            'holster' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_holster_128x128.png?v=2',
            'ornament' => 'https://warframe.market/static/assets/sub_icons/weapon/prime_ornament_128x128.png?v=2',
            'blueprint' => 'https://warframe.market/static/assets/sub_icons/blueprint_128x128.png?v=2',
        ],
        'other' => [
            'odonata_prime_wings' => 'https://warframe.market/static/assets/sub_icons/archwing/prime_wings_128x128.png',
            'odonata_prime_systems' => 'https://warframe.market/static/assets/sub_icons/archwing/prime_systems_128x128.png',
            'odonata_prime_harness' => 'https://warframe.market/static/assets/sub_icons/archwing/prime_chassis_128x128.png',
            'buckle' => 'https://warframe.market/static/assets/sub_icons/pets/prime_buckle_128x128.png',
            'band' => 'https://warframe.market/static/assets/sub_icons/pets/prime_band_128x128.png',
            'cerebrum' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_helmet_128x128.png?v=2',
            'carapace' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_chassis_128x128.png?v=2',
            'systems' => 'https://warframe.market/static/assets/sub_icons/warframe/prime_systems_128x128.png?v=2',
            'blueprint' => 'https://warframe.market/static/assets/sub_icons/blueprint_128x128.png?v=2',
        ],
    ];

    public function resolve(string $category, string $slug, ?string $fallback): ?string
    {
        foreach (self::PART_ICON_MAP[$category] ?? [] as $needle => $imageUrl) {
            if (str_contains($slug, $needle)) {
                return $imageUrl;
            }
        }

        return $fallback;
    }
}
