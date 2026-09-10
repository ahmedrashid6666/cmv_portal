<?php

use App\Support\AmountInWords;

it('spells out whole and fractional AED amounts', function () {
    expect(AmountInWords::convert(0, 'AED'))->toBe('Zero Dirhams Only')
        ->and(AmountInWords::convert(1, 'AED'))->toBe('One Dirham Only')
        ->and(AmountInWords::convert(110, 'AED'))->toBe('One Hundred Ten Dirhams Only')
        ->and(AmountInWords::convert(1234.50, 'AED'))->toBe('One Thousand Two Hundred Thirty Four Dirhams and Fifty Fils Only')
        ->and(AmountInWords::convert(2000000, 'AED'))->toBe('Two Million Dirhams Only');
});

it('uses Rial/Baisa for OMR', function () {
    expect(AmountInWords::convert(45.5, 'OMR'))->toBe('Forty Five Rials and Fifty Baisa Only')
        ->and(AmountInWords::convert(1, 'OMR'))->toBe('One Rial Only');
});
