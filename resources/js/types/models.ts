import { type MotionStyle } from '@/lib/motion-styles';

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
    import_status: 'importing' | 'awaiting_reply' | 'done' | 'failed' | null;
    import_error: string | null;
    import_prompt?: string;
    claude_web_url: string;
    images_approved: boolean;
    primary_image_url: string | null;
    images?: ProductImage[];
    images_count?: number;
    video_projects_count?: number;
    /** Loại sản phẩm (danh sách sản phẩm). */
    category?: string | null;
    created_at: string;
}

export type FashionModelKind = 'after' | 'before';

export interface FashionModel {
    id: number;
    name: string;
    /** after: đã makeup; before: mặt mộc, tóc rối, đồ cũ (ảnh "trước" của video biến hình). */
    kind: FashionModelKind;
    photo_url: string;
    has_consent: boolean;
    consent_at: string | null;
}

export type VideoProjectStatus = 'draft' | 'scripting' | 'script_ready' | 'imaging' | 'reviewing' | 'rendering' | 'done' | 'failed';

export interface Script {
    hooks: string[];
    /** Chủ đề từng hook (Tò mò, Người mua nói gì...). */
    hook_themes?: string[];
    /** Hook Claude đề xuất để mở video. */
    recommended_hook?: number;
    caption: string;
    hashtags: string[];
}

export interface SceneImage {
    id: number;
    status: 'pending' | 'ready' | 'approved' | 'rejected' | 'failed';
    url: string | null;
    error: string | null;
}

/** Bối cảnh người dùng cung cấp (menu Bối cảnh). */
export interface Background {
    id: number;
    name: string;
    description: string | null;
    photo_url: string | null;
    scenes_count?: number;
}

export interface Scene {
    id: number;
    background: Background | null;
    position: number;
    /** Video biến hình: cảnh trước / sau. Video thường: null. */
    phase: 'before' | 'after' | null;
    duration_ms: number;
    visual: string;
    on_screen_text: string | null;
    voiceover: string | null;
    /** Prompt chỉ có với admin (hoặc chế độ thủ công phải tự dán prompt). */
    image_prompt?: string;
    full_prompt?: string;
    motion_prompt?: string | null;
    motion_style_label: string | null;
    sample_video_id: number | null;
    /** Video mẫu đang dùng cho cảnh (của cảnh hoặc của dự án). */
    motion_reference: SampleVideoRef | null;
    clip_url: string | null;
    clip_status: 'pending' | 'ready' | 'failed' | null;
    clip_error: string | null;
    image: SceneImage | null;
}

export interface Render {
    id: number;
    hook: number;
    status: 'pending' | 'done' | 'failed';
    progress: number;
    url: string | null;
    download_url: string | null;
    duration_ms: number | null;
    error: string | null;
    created_at: string;
}

export interface VideoPresetOption {
    key: string;
    label: string;
    seconds: number;
    scenes: number;
    hint: string;
    recommended: boolean;
}

export interface VideoSettings {
    scenes: number;
    seconds: number;
    preset: string | null;
    mode: 'auto' | 'fixed';
    reason: string;
}

export type VideoType = 'standard' | 'transformation';

export interface VideoProject {
    id: number;
    /** Bối cảnh người dùng đã chọn (rỗng = AI tự đề xuất). */
    background_ids: number[];
    status: VideoProjectStatus;
    is_busy: boolean;
    clips_available: boolean;
    progress: { percent: number; label: string };
    autopilot: boolean;
    autopilot_blocker?: string | null;
    error: string | null;
    script: Script | null;
    selected_hook: number;
    has_music: boolean;
    audio_track: { id: number; name: string; url: string | null } | null;
    script_mode: 'web' | 'api';
    claude_web_url: string;
    script_prompt?: string;
    image_mode: 'web' | 'api';
    muse_web_url: string;
    motion_style: MotionStyle | null;
    motion_styles: MotionStyle[];
    /** Có chuyển động: kiểu có sẵn hoặc video mẫu. */
    has_motion: boolean;
    /** Video mẫu mặc định để sao chép chuyển động. */
    sample_video: SampleVideoRef | null;
    motion_style_label: string | null;
    motion_web_url: string;
    references?: { label: string; url: string }[];
    video_settings?: VideoSettings;
    scene_suggestions?: string[];
    motion_suggestions?: MotionStyle[];
    product_category?: string | null;
    product?: { id: number; name: string; primary_image_url: string | null };
    fashion_model?: { id: number; name: string; photo_url: string };
    video_type: VideoType;
    video_type_label: string;
    /** Ảnh "trước" của video biến hình. */
    before_fashion_model?: { id: number; name: string; photo_url: string } | null;
    scenes?: Scene[];
    all_approved?: boolean;
    renders?: Render[];
    /** Video xuất xong gần nhất (danh sách dự án). */
    latest_render?: {
        id: number;
        url: string | null;
        download_url: string;
        duration_ms: number | null;
        hook: string | null;
        created_at: string;
    } | null;
    created_at: string;
    updated_at: string;
}

export interface SampleVideoRef {
    id: number;
    name: string;
    url: string | null;
    thumbnail_url: string | null;
}

export interface SampleVideo {
    id: number;
    name: string;
    url: string;
    thumbnail_url: string | null;
    mime: string | null;
    size_bytes: number;
    duration_ms: number | null;
    width: number | null;
    height: number | null;
    source_url: string | null;
    notes: string | null;
    /** Phân tích chuyển động (Claude): pending / ready / failed, null = chưa phân tích. */
    analysis_status: 'pending' | 'ready' | 'failed' | null;
    analysis: { summary: string; tempo: string; framing: string; steps: { start: number; end: number; motion: string }[] } | null;
    analysis_error: string | null;
    has_audio: boolean | null;
    /** Bài nhạc tách từ video (thư viện Âm thanh). */
    audio_track_id: number | null;
    created_at: string;
}

export interface AudioTrack {
    id: number;
    name: string;
    url: string | null;
    source_url: string | null;
    /** Có file nhạc để ghép vào video (không chỉ là link). */
    playable: boolean;
    size_bytes: number;
    duration_ms: number | null;
    used_count?: number;
}
