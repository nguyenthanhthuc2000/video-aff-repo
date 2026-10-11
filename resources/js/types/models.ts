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
    outfit?: SceneOutfit | null;
    position: number;
    /** Video biến hình: cảnh trước / sau. Video thường: null. */
    phase: 'before' | 'after' | null;
    /** Góc quay sản phẩm của cảnh (toàn thân, cận chất vải, mặt sau...). */
    angle?: string | null;
    angle_label?: string | null;
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
    with_text?: boolean;
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

/** Tiếng trong video: chỉ nhạc nền / người mẫu nói lời review / hát nhép theo nhạc nền. */
export type VoiceMode = 'music' | 'speak' | 'sing';

export interface VideoProject {
    id: number;
    /** Bối cảnh người dùng đã chọn (rỗng = AI tự đề xuất). */
    background_ids: number[];
    outfits?: Outfit[];
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
    /** Một cảnh quay liền (one take): các nhịp nối nhau bằng khung cuối clip trước. */
    one_take?: boolean;
    voice_mode?: VoiceMode;
    /** Góc quay sản phẩm chọn được cho từng cảnh (rỗng = không chia góc, ví dụ video mẫu / biến hình). */
    angle_options?: { value: string; label: string }[];
    /** Hát nhép: đoạn nhạc bắt đầu từ (ms), AI chọn khi tạo clip đầu tiên. */
    music_start_ms?: number | null;
    muse_plan_status?: 'pending' | 'ready' | 'failed' | null;
    muse_plan_error?: string | null;
    muse_plan?: MusePlan | null;
    post_captions_status?: 'pending' | 'ready' | 'failed' | null;
    post_captions?: PostCaptions | null;
    social_posts?: SocialPost[];
    render_text?: boolean;
    /** Mọi sản phẩm trong video (video gộp: theo thứ tự bộ đồ). */
    video_products?: {
        id: number;
        name: string;
        price: number | null;
        shopee_url: string | null;
        url: string;
        outfit_numbers: number[];
        colors: string[];
        scenes: number[];
        images: { id: number; url: string | null; used: boolean }[];
    }[];
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

/** Bộ đồ: sản phẩm, ảnh đúng màu / mẫu (null = mọi ảnh), màu. */
export type Outfit = {
    product_id: number;
    product_image_id: number | null;
    color: string | null;
};

/** Bộ đồ của một cảnh (đã tra tên, ảnh). */
export interface SceneOutfit extends Outfit {
    product_name: string;
    image_url: string | null;
}

/** Sản phẩm chọn được làm bộ đồ. */
export interface OutfitProduct {
    id: number;
    name: string;
    colors: string[];
    images: { id: number; url: string | null }[];
}

export interface SampleVideo {
    id: number;
    /** Các shot (đoạn giữa hai lần cắt cảnh), rỗng = một shot. */
    shots?: { start: number; end: number }[];
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
    /** Phân tích giọng hát (hát nhép): null = chưa phân tích. */
    vocal_status?: 'pending' | 'ready' | 'failed' | null;
    vocal_error?: string | null;
    vocal_summary?: { sung_seconds: number; lines: { start: number; end: number; text: string }[] } | null;
    /** Lời bài hát chuẩn người dùng dán (thay chữ Whisper nghe nhầm). */
    lyrics?: string | null;
}

export interface MusePlanShot {
    duration: number;
    camera: string;
    action: string;
    expression: string;
    product_focus: string;
    sample_start: number | null;
    sample_end: number | null;
    mouth: { start: number; end: number; state: 'sing' | 'closed'; words: string }[];
}

export interface MusePlan {
    music_start: number | null;
    tempo_bpm: number | null;
    mood: string;
    summary: string;
    song_lines: { start: number; end: number; text: string }[];
    shots: MusePlanShot[];
}

/** Đề xuất caption đăng video: bản bán hàng và bản câu view (error khi viết lỗi). */
export interface PostCaptions {
    sale?: string[];
    view?: string[];
    error?: string;
}

/** Trang Facebook (hoặc tài khoản khác) đã kết nối để đăng video. */
export interface SocialAccount {
    id: number;
    provider: 'facebook';
    name: string;
    external_id: string;
}

/** Một lần đăng video lên mạng xã hội. */
export interface SocialPost {
    id: number;
    provider: string;
    account_name: string | null;
    caption: string;
    status: 'pending' | 'uploading' | 'processing' | 'published' | 'failed';
    permalink: string | null;
    error: string | null;
    published_at: string | null;
    created_at: string;
}
