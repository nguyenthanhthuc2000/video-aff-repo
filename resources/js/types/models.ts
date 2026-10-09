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

export type VideoProjectStatus = 'draft' | 'scripting' | 'script_ready' | 'imaging' | 'reviewing' | 'rendering' | 'done' | 'failed';

export interface Script {
    hooks: string[];
    caption: string;
    hashtags: string[];
}

export interface SceneImage {
    id: number;
    status: 'pending' | 'ready' | 'approved' | 'rejected' | 'failed';
    url: string | null;
    error: string | null;
}

export interface Scene {
    id: number;
    position: number;
    duration_ms: number;
    visual: string;
    on_screen_text: string | null;
    voiceover: string | null;
    image_prompt: string;
    full_prompt: string;
    image: SceneImage | null;
}

export interface Render {
    id: number;
    hook: number;
    status: 'pending' | 'done' | 'failed';
    url: string | null;
    download_url: string | null;
    duration_ms: number | null;
    error: string | null;
    created_at: string;
}

export interface VideoProject {
    id: number;
    status: VideoProjectStatus;
    is_busy: boolean;
    error: string | null;
    script: Script | null;
    selected_hook: number;
    has_music: boolean;
    script_mode: 'web' | 'api';
    claude_web_url: string;
    script_prompt?: string;
    image_mode: 'web' | 'api';
    muse_web_url: string;
    references?: { label: string; url: string }[];
    image_cost_usd?: number;
    product?: { id: number; name: string; primary_image_url: string | null };
    fashion_model?: { id: number; name: string; photo_url: string };
    scenes?: Scene[];
    all_approved?: boolean;
    renders?: Render[];
    created_at: string;
    updated_at: string;
}
