export interface ProductDetails {
    sizes?: string | null;
    material?: string | null;
    colors?: string | null;
    notes?: string | null;
}

export interface ProductImage {
    id: number;
    url: string;
    is_primary: boolean;
}

export interface Product {
    id: number;
    name: string;
    shopee_url: string | null;
    price: number | null;
    details: ProductDetails;
    reviews: string | null;
    primary_image_url: string | null;
    images?: ProductImage[];
    created_at: string;
}

export interface FashionModel {
    id: number;
    name: string;
    photo_url: string;
    has_consent: boolean;
    consent_at: string | null;
}
