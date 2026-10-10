<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // Cách sản phẩm xuất hiện trên người mẫu (tiếng Anh, đưa vào prompt ảnh/clip): đội trên đầu, cầm trên tay...
            $table->text('usage')->nullable()->after('motion_styles');
        });

        foreach (self::USAGE as $key => $usage) {
            DB::table('product_categories')->where('key', $key)->whereNull('usage')->update(['usage' => $usage]);
        }
    }

    public const USAGE = [
        'phone' => 'holding a phone fitted with the exact phone case from the product photos, the case clearly visible',
        'beauty' => 'holding the exact cosmetic product from the product photos in one hand, the packaging facing the camera',
        'shoes' => 'wearing the exact shoes from the product photos on her feet, the shoes clearly visible',
        'bag' => 'carrying the exact bag from the product photos on her shoulder or in her hand',
        'wearable' => 'wearing the exact accessory from the product photos where it is normally worn (a hat on her head, glasses on her face, jewelry on her ears, neck or wrist)',
        'clothing' => 'wearing the exact garment from the product photos',
        'other' => 'holding or using the exact product from the product photos, the product clearly visible',
    ];

    public function down(): void
    {
        Schema::table('product_categories', fn (Blueprint $table) => $table->dropColumn('usage'));
    }
};
