<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:255'],
            'shopee_url' => ['nullable', 'url', 'max:2048'],
            'price' => ['nullable', 'integer', 'min:0'],
            'details' => ['nullable', 'array'],
            'details.sizes' => ['nullable', 'string', 'max:255'],
            'details.material' => ['nullable', 'string', 'max:255'],
            'details.colors' => ['nullable', 'string', 'max:255'],
            'details.notes' => ['nullable', 'string', 'max:2000'],
            'reviews' => ['nullable', 'string', 'max:5000'],
            'images' => [$creating ? 'required' : 'nullable', 'array', 'max:'.config('videoaff.product_images_max')],
            'images.*' => ['image', 'max:10240'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên sản phẩm',
            'shopee_url' => 'link Shopee',
            'price' => 'giá',
            'images' => 'ảnh sản phẩm',
            'images.*' => 'ảnh sản phẩm',
        ];
    }
}
