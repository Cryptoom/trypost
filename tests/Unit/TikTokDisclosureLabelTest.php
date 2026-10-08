<?php

declare(strict_types=1);

// PATCH:tiktok-disclosure-label
test('ttl01 english keeps the upstream toggle label and shows the TikTok sentence as description', function () {
    expect(trans('posts.form.tiktok.disclose', [], 'en'))->toBe('Disclose video content');
    expect(trans('posts.form.tiktok.disclose_description', [], 'en'))
        ->toBe('Indicate whether this content promotes yourself, a brand, product or service.');
});

test('ttl01 every locale carries the exact translated disclose_description', function (string $locale, string $expected) {
    expect(trans('posts.form.tiktok.disclose_description', [], $locale))->toBe($expected);
})->with(fn () => collect([
    'ar' => 'حدّد ما إذا كان هذا المحتوى يروّج لك أو لعلامة تجارية أو لمنتج أو لخدمة.',
    'de' => 'Gib an, ob dieser Inhalt dich selbst, eine Marke, ein Produkt oder eine Dienstleistung bewirbt.',
    'el' => 'Δηλώστε αν αυτό το περιεχόμενο προωθεί τον εαυτό σας, μια μάρκα, ένα προϊόν ή μια υπηρεσία.',
    'en' => 'Indicate whether this content promotes yourself, a brand, product or service.',
    'es' => 'Indica si este contenido te promociona a ti, a una marca, un producto o un servicio.',
    'fr' => 'Indiquez si ce contenu fait la promotion de vous-même, d\'une marque, d\'un produit ou d\'un service.',
    'it' => 'Indica se questo contenuto promuove te stesso, un marchio, un prodotto o un servizio.',
    'ja' => 'このコンテンツが自分自身、ブランド、商品、またはサービスを宣伝するかどうかを示してください。',
    'ko' => '이 콘텐츠가 본인, 브랜드, 제품 또는 서비스를 홍보하는지 표시하세요.',
    'nl' => 'Geef aan of deze content jezelf, een merk, product of dienst promoot.',
    'pl' => 'Wskaż, czy ta treść promuje ciebie, markę, produkt lub usługę.',
    'pt-BR' => 'Indique se este conteúdo promove você, uma marca, um produto ou um serviço.',
    'ru' => 'Укажите, продвигает ли этот контент вас, бренд, продукт или услугу.',
    'tr' => 'Bu içeriğin sizi, bir markayı, ürünü veya hizmeti tanıtıp tanıtmadığını belirtin.',
    'uk' => 'Вкажіть, чи просуває цей вміст вас, бренд, продукт або послугу.',
    'zh' => '请说明此内容是否在推广你自己、品牌、产品或服务。',
])->map(fn (string $text, string $locale) => [$locale, $text])->all());
