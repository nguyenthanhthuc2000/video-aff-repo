// Dịch vụ trình duyệt ngầm: đăng nhập muse.ai bằng tài khoản người dùng (email + mã OTP) và gửi lệnh tạo ảnh.
// Laravel gọi qua HTTP trong mạng Docker nội bộ. Phiên đăng nhập lưu trong /data/profile (volume) để không phải đăng nhập lại.
// Giao diện muse.ai có thể thay đổi: các bộ chọn bên dưới dựa vào role/label/autocomplete thay vì class.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import xterm from '@xterm/headless';
import { chromium } from 'playwright';

const PORT = Number(process.env.PORT ?? 3000);
const TOKEN = process.env.BROWSER_TOKEN ?? '';
const BASE_URL = process.env.MUSE_URL ?? 'https://muse.ai/';
const PROFILE_DIR = process.env.PROFILE_DIR ?? '/data/profile';
const GENERATE_TIMEOUT_MS = Number(process.env.GENERATE_TIMEOUT_MS ?? 480000);

const SELECTORS = {
    username: 'input[autocomplete="username"], input[aria-label="Mobile number or email"]',
    code: [
        'input[autocomplete="one-time-code"]',
        'input[inputmode="numeric"]',
        'input[name*="code" i]',
        'input[aria-label*="code" i]',
        'input[placeholder*="code" i]',
        'input[aria-label*="mã" i]',
    ].join(', '),
    password: 'input[type="password"]',
    composer: [
        'textarea',
        '[contenteditable="true"][role="textbox"]',
        'div[contenteditable="true"]',
        'input[placeholder*="Muse" i]',
        'input[placeholder*="message" i]',
        'input[placeholder*="ask" i]',
    ].join(', '),
    submit: /^(continue|next|confirm|verify|submit|log in|tiếp tục|tiếp|xác nhận|xác minh|đăng nhập|gửi)$/i,
    attach: /attach|upload|add (photo|image|file)|photo|image|đính kèm|tải lên|ảnh|tệp/i,
};

/** @type {{ state: string, message: string, email: string | null, updatedAt: string }} */
let status = { state: 'starting', message: 'Đang khởi động trình duyệt...', email: null, updatedAt: new Date().toISOString() };
let context = null;
let page = null;
let chain = Promise.resolve();

function setStatus(state, message, extra = {}) {
    status = { ...status, state, message, ...extra, updatedAt: new Date().toISOString() };
    console.log(`[status] ${state}: ${message}`);
}

/** Chạy lần lượt: một trình duyệt chỉ làm một việc mỗi lúc. */
function serial(task) {
    const run = chain.then(task, task);
    chain = run.catch(() => {});
    return run;
}

async function getPage() {
    if (!context) {
        context = await chromium.launchPersistentContext(PROFILE_DIR, {
            headless: true,
            viewport: { width: 1280, height: 900 },
            locale: 'vi-VN',
            userAgent:
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        });
        // Ghi các request lỗi của muse.ai để chẩn đoán khi đăng nhập không qua.
        context.on('response', (response) => {
            if (response.status() >= 400 && response.url().includes('muse.ai')) {
                console.log(`[http ${response.status()}] ${response.request().method()} ${response.url().slice(0, 160)}`);
            }
        });
        context.on('close', () => {
            context = null;
            page = null;
        });
    }
    if (!page || page.isClosed()) {
        page = context.pages()[0] ?? (await context.newPage());
    }
    return page;
}

async function visible(locator, timeout = 0) {
    // waitFor với timeout rất nhỏ có thể hết giờ trước khi kịp kiểm tra; kiểm tra tức thời bằng isVisible.
    if (timeout <= 1) {
        return locator.first().isVisible().catch(() => false);
    }
    try {
        await locator.first().waitFor({ state: 'visible', timeout });
        return true;
    } catch {
        return false;
    }
}

/** Xem trang đang ở bước nào. */
async function detect(p, timeout = 0) {
    const deadline = Date.now() + timeout;
    do {
        if (await visible(p.locator(SELECTORS.code), 1)) return 'awaiting_code';
        if (await visible(p.locator(SELECTORS.password), 1)) return 'awaiting_password';
        if (await visible(p.locator(SELECTORS.username), 1)) return 'logged_out';
        if (await visible(p.locator(SELECTORS.composer), 1)) return 'logged_in';
        await p.waitForTimeout(500);
    } while (Date.now() < deadline);
    return 'unknown';
}

const STATE_MESSAGES = {
    logged_in: 'Đã đăng nhập muse.ai.',
    logged_out: 'Chưa đăng nhập. Nhập email hoặc số điện thoại.',
    awaiting_code: 'muse.ai đã gửi mã xác thực. Nhập mã OTP.',
    awaiting_password: 'muse.ai yêu cầu mật khẩu.',
    unknown: 'Không nhận ra màn hình hiện tại. Xem ảnh chụp để biết trình duyệt đang ở đâu.',
};

async function refreshStatus(navigate = true) {
    const p = await getPage();
    if (navigate && !p.url().startsWith(BASE_URL)) {
        await p.goto(BASE_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
    }
    const state = await detect(p, 15000);
    // Giữ thông báo lỗi gần nhất nếu vẫn ở cùng bước.
    if (state !== status.state || !['logged_out', 'awaiting_code', 'awaiting_password'].includes(state)) {
        setStatus(state, STATE_MESSAGES[state]);
    }
    return status;
}

/**
 * Gửi form chứa ô vừa nhập. Không tìm nút theo tên trên cả trang: nút "Đăng nhập" ở đầu trang
 * cũng khớp và sẽ bị bấm nhầm thay cho "Tiếp tục".
 */
async function clickSubmit(p, input) {
    const formButton = input.locator('xpath=ancestor::form[1]').locator('button[type="submit"], button:not([type])');
    if (await visible(formButton, 1)) {
        await formButton.first().click();
        return;
    }
    const named = p.getByRole('button', { name: SELECTORS.submit }).filter({ hasNotText: /^(log in|đăng nhập)$/i });
    if (await visible(named, 1)) {
        await named.last().click();
        return;
    }
    await input.press('Enter');
}

/** Tìm thông báo lỗi trên trang (sai mã, quá nhiều lần thử...). */
async function pageError(p) {
    const alert = p.locator('[role="alert"], [aria-live="assertive"]');
    if (await visible(alert, 1)) {
        const text = (await alert.first().innerText()).trim();
        if (text) return text.slice(0, 300);
    }
    return null;
}

async function afterStep(p, fallbackState, failMessage) {
    const state = await detect(p, 25000);
    if (state === fallbackState || state === 'unknown') {
        const error = await pageError(p);
        setStatus(state, error ? `muse.ai báo: ${error}` : failMessage);
    } else {
        setStatus(state, STATE_MESSAGES[state]);
    }
    return status;
}

async function login(email) {
    const p = await getPage();
    await p.goto(BASE_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
    let state = await detect(p, 15000);
    if (state === 'logged_in') {
        setStatus('logged_in', STATE_MESSAGES.logged_in);
        return status;
    }
    if (state !== 'logged_out') {
        const loginButton = p.getByRole('button', { name: /^(log in|đăng nhập)$/i });
        if (await visible(loginButton, 1)) await loginButton.first().click();
        state = await detect(p, 10000);
    }
    if (state !== 'logged_out') {
        setStatus(state, STATE_MESSAGES[state]);
        return status;
    }

    const input = p.locator(SELECTORS.username).first();
    await input.fill(email);
    await clickSubmit(p, input);
    setStatus('submitting', 'Đã gửi email, đang chờ muse.ai...', { email });
    return afterStep(p, 'logged_out', 'muse.ai chưa chuyển sang bước nhập mã. Kiểm tra email/số điện thoại hoặc xem ảnh chụp.');
}

async function submitCode(code) {
    const p = await getPage();
    const inputs = p.locator(SELECTORS.code);
    const count = await inputs.count();
    // Ô mã tách từng chữ số (maxlength=1): gõ lần lượt từ ô đầu.
    if (count > 1 && (await inputs.first().getAttribute('maxlength')) === '1') {
        await inputs.first().click();
        await p.keyboard.type(code, { delay: 80 });
    } else {
        await inputs.first().fill(code);
    }
    await clickSubmit(p, inputs.first());
    setStatus('submitting', 'Đang xác thực mã...');
    return afterStep(p, 'awaiting_code', 'Mã chưa được chấp nhận. Kiểm tra lại mã hoặc xem ảnh chụp.');
}

async function submitPassword(password) {
    const p = await getPage();
    const input = p.locator(SELECTORS.password).first();
    await input.fill(password);
    await clickSubmit(p, input);
    setStatus('submitting', 'Đang đăng nhập...');
    return afterStep(p, 'awaiting_password', 'Mật khẩu chưa được chấp nhận. Xem ảnh chụp.');
}

async function logout() {
    if (context) await context.close().catch(() => {});
    await rm(PROFILE_DIR, { recursive: true, force: true });
    context = null;
    page = null;
    setStatus('logged_out', 'Đã đăng xuất và xoá phiên đăng nhập.', { email: null });
    return status;
}

async function attachFiles(p, paths) {
    const input = p.locator('input[type="file"]');
    if ((await input.count()) > 0) {
        await input.first().setInputFiles(paths);
        return;
    }
    const button = p.getByRole('button', { name: SELECTORS.attach });
    if (!(await visible(button, 3000))) {
        throw new Error('Không tìm thấy nút đính kèm ảnh trên muse.ai.');
    }
    const [chooser] = await Promise.all([p.waitForEvent('filechooser', { timeout: 10000 }), button.first().click()]);
    await chooser.setFiles(paths);
}

// Ảnh muse.ai tạo ra: nằm trong khối "presentation image" hoặc nút "Mở hình ảnh đã tạo …".
// Ảnh tham chiếu đính kèm trong tin nhắn của mình không có các dấu hiệu này.
const GENERATED_IMAGES = [
    '[data-testid="hatch-chat-attachment-presentation-image"] img',
    'button[aria-label^="Mở hình ảnh đã tạo"] img',
    'button[aria-label^="Open generated image" i] img',
].join(', ');

async function download(p, src) {
    if (src.startsWith('data:') || src.startsWith('blob:')) {
        const data = await p.evaluate(async (url) => {
            const blob = await (await fetch(url)).blob();
            const buffer = new Uint8Array(await blob.arrayBuffer());
            let binary = '';
            for (let i = 0; i < buffer.length; i += 0x8000) binary += String.fromCharCode(...buffer.subarray(i, i + 0x8000));
            return { mime: blob.type, base64: btoa(binary) };
        }, src);
        return data;
    }
    const response = await p.context().request.get(src, { timeout: 60000 });
    if (!response.ok()) throw new Error(`Tải ảnh kết quả lỗi HTTP ${response.status()}.`);
    return { mime: response.headers()['content-type'] ?? 'image/jpeg', base64: (await response.body()).toString('base64') };
}

// ---- Yêu cầu tạo ảnh / video (chạy song song, mỗi yêu cầu một mã riêng) ----
// Muse là agent: nhận nhiều tin nhắn liên tiếp có thể trả gộp nhiều ảnh trong một câu trả lời.
// Mỗi tin nhắn mang một mã (VA-XXXXXX) và yêu cầu Muse đặt mã đó làm tiêu đề ảnh/video ("Mở hình ảnh đã tạo VA-…"),
// nên kết quả được nhận theo mã. Muse không đặt tiêu đề theo mã thì lấy lần lượt theo thứ tự gửi.

const CODE_PATTERN = /VA-[A-Z0-9]{6}/g;
/** @type {Map<string, {code: string, kind: 'image'|'video', sentAt: number, deadline: number, state: string, error?: string, result?: object, candidate?: string|null}>} */
const tasks = new Map();
const claimed = new Set();
let pollTimer = null;

async function ensureReady(p) {
    // Không tải lại trang khi đang có yêu cầu chờ: giữ nguyên ảnh/video đang hiển thị.
    if (!p.url().startsWith(BASE_URL) || !(await visible(p.locator(SELECTORS.composer), 1))) {
        await p.goto(BASE_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
    }
    const state = await detect(p, 20000);
    if (state !== 'logged_in') {
        setStatus(state, STATE_MESSAGES[state]);
        const error = new Error('Chưa đăng nhập muse.ai. Vào màn hình Xác thực Muse để đăng nhập.');
        error.status = 409;
        throw error;
    }
}

/**
 * Gửi một yêu cầu. adopt = chỉ theo dõi mã đã gửi trước đó (dịch vụ khởi động lại, mất danh sách trong bộ nhớ),
 * không gửi tin nhắn mới.
 */
async function sendTask({ code, kind, prompt, images, timeoutMs, adopt = false }) {
    if (!/^VA-[A-Z0-9]{6}$/.test(code)) {
        const error = new Error('Mã yêu cầu không hợp lệ.');
        error.status = 422;
        throw error;
    }
    if (tasks.has(code)) return publicTask(tasks.get(code), false);

    const p = await getPage();
    await ensureReady(p);

    if (adopt) {
        const task = { code, kind, sentAt: Date.now(), deadline: Date.now() + timeoutMs, state: 'pending', candidate: null };
        tasks.set(code, task);
        console.log(`[task ${code}] theo dõi lại (${kind})`);
        schedulePoll();
        return publicTask(task, false);
    }

    const dir = await mkdtemp(join(tmpdir(), 'muse-'));
    try {
        const paths = [];
        for (const [i, image] of images.entries()) {
            const path = join(dir, `${code}-${i + 1}-${String(image.name ?? 'ref.jpg').replace(/[^\w.-]/g, '')}`);
            await writeFile(path, Buffer.from(image.base64, 'base64'));
            paths.push(path);
        }
        if (paths.length > 0) {
            await attachFiles(p, paths);
            await p.waitForTimeout(3000);
        }
        const composer = p.locator(SELECTORS.composer).first();
        await composer.click();
        await composer.fill(prompt).catch(async () => p.keyboard.type(prompt));
        await p.keyboard.press('Enter');
    } finally {
        await rm(dir, { recursive: true, force: true });
    }

    const task = { code, kind, sentAt: Date.now(), deadline: Date.now() + timeoutMs, state: 'pending', candidate: null };
    tasks.set(code, task);
    console.log(`[task ${code}] đã gửi (${kind})`);
    schedulePoll();
    return publicTask(task, false);
}

const GENERATED_VIDEOS = '[data-testid="hatch-chat-attachment-presentation-video"]';
// Lời từ chối thường gặp của Muse (tiếng Anh / tiếng Việt).
const REFUSAL_PATTERN =
    /couldn['’]t|could not|can['’]t|cannot|unable to|not able to|won['’]t|declin|refus|not allowed|against (the|our) (policy|guidelines)|không thể|không tạo được|từ chối|không được phép/;

/**
 * Quét trang: mã nào đã xuất hiện, và mỗi ảnh/video đã tạo thuộc mã nào.
 * Mã của một ảnh/video = tiêu đề ảnh nếu chứa mã, nếu không thì mã gần nhất phía trước nó
 * (Muse ghi mã làm tiêu đề ngay trước kết quả; không có thì là mã trong tin nhắn mình gửi).
 */
async function scan(p) {
    return p.evaluate(
        ({ imageSelector, videoSelector, codeSource, refusalSource }) => {
            const occurrences = [];
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                const el = node.parentElement;
                if (!el || el.closest('textarea, [contenteditable="true"]')) continue;
                // Trong câu trả lời của Muse chỉ tính mã đứng riêng (tiêu đề), bỏ mã được nhắc trong đoạn văn
                // (ví dụ tin từ chối nhắc mã của yêu cầu khác) để không ghép nhầm kết quả.
                const inReply = el.closest('[data-hatch-assistant-message-body]');
                for (const code of node.textContent.match(new RegExp(codeSource, 'g')) ?? []) {
                    if (!inReply || el.textContent.trim() === code) occurrences.push({ code, el });
                }
            }
            const nearestCode = (target) => {
                let found = null;
                for (const occ of occurrences) if (occ.el.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING) found = occ.code;
                return found;
            };
            const idOf = (el) => (el.dataset.vaId ||= Math.random().toString(36).slice(2, 10));

            const images = [...document.querySelectorAll(imageSelector)].map((img) => {
                const label = img.closest('button')?.getAttribute('aria-label') ?? '';
                const src = img.currentSrc || img.src;
                return {
                    id: idOf(img),
                    kind: 'image',
                    src,
                    ready: !!src && img.complete && img.naturalWidth >= 400 && img.naturalHeight >= 400,
                    code: label.match(new RegExp(codeSource))?.[0] ?? nearestCode(img),
                    byTitle: new RegExp(codeSource).test(label),
                };
            });
            const videos = [...document.querySelectorAll(videoSelector)].map((block) => {
                // Nút "Tải video xuống" nằm cùng bong bóng tin nhắn với khối video.
                let box = block;
                let button = null;
                for (let i = 0; i < 6 && box && !button; i++, box = box.parentElement) button = box.querySelector('button[data-pel-click="media_download"]');
                const id = idOf(block);
                if (button) button.dataset.vaDownload = id;
                return { id, kind: 'video', src: null, ready: !!button, code: nearestCode(block), byTitle: false };
            });
            // Câu trả lời dạng chữ (đã viết xong, không kèm ảnh/video) có ý từ chối: Muse không tạo yêu cầu đó.
            // Mã = mã nhắc trong câu trả lời; không có thì là mã trong tin gần nhất của mình phía trước nó.
            const refusals = [...document.querySelectorAll('[data-message-role="assistant"]')]
                .filter((msg) => !msg.querySelector('[data-hatch-markdown-streaming="true"], [aria-busy="true"]'))
                .filter((msg) => !msg.querySelector(`${imageSelector}, ${videoSelector}`))
                .map((msg) => {
                    const text = (msg.querySelector('[data-hatch-assistant-message-body]')?.innerText ?? '').trim();
                    let codes = [...new Set(text.match(new RegExp(codeSource, 'g')) ?? [])];
                    if (codes.length === 0) {
                        const users = [...document.querySelectorAll('[data-message-role="user"]')].filter(
                            (user) => user.compareDocumentPosition(msg) & Node.DOCUMENT_POSITION_FOLLOWING,
                        );
                        const code = users.at(-1)?.innerText.match(new RegExp(codeSource))?.[0];
                        codes = code ? [code] : [];
                    }
                    return { text, codes };
                })
                .filter((reply) => reply.codes.length > 0 && reply.text.length > 20 && new RegExp(refusalSource, 'i').test(reply.text));
            return { anchors: [...new Set(occurrences.map((occ) => occ.code))], media: [...images, ...videos], refusals };
        },
        { imageSelector: GENERATED_IMAGES, videoSelector: GENERATED_VIDEOS, codeSource: CODE_PATTERN.source, refusalSource: REFUSAL_PATTERN.source },
    );
}

/** Tải video bằng nút "Tải video xuống" của muse.ai (Chromium không phát được H.264 nên không đọc thẻ video). */
async function downloadVideo(p, id) {
    const [file] = await Promise.all([p.waitForEvent('download', { timeout: 120000 }), p.locator(`[data-va-download="${id}"]`).first().click()]);
    const path = await file.path();
    const name = file.suggestedFilename().toLowerCase();
    const mime = name.endsWith('.webm') ? 'video/webm' : name.endsWith('.mov') ? 'video/quicktime' : 'video/mp4';
    return { mime, base64: (await readFile(path)).toString('base64') };
}

function schedulePoll() {
    if (pollTimer) return;
    pollTimer = setTimeout(() => {
        pollTimer = null;
        serial(pollTasks)
            .catch((error) => console.error('[poll]', error.message))
            .finally(() => [...tasks.values()].some((task) => task.state === 'pending') && schedulePoll());
    }, 4000);
}

async function pollTasks() {
    const pending = [...tasks.values()].filter((task) => task.state === 'pending').sort((a, b) => a.sentAt - b.sentAt);
    if (pending.length === 0) return;
    const p = await getPage();
    const { anchors, media, refusals } = await scan(p);

    for (const task of pending) {
        if (Date.now() > task.deadline) {
            fail(task, `Hết thời gian mà muse.ai chưa trả ${task.kind === 'video' ? 'video' : 'ảnh'}.`);
            continue;
        }
        if (!anchors.includes(task.code)) {
            if (Date.now() - task.sentAt > 90000) fail(task, 'Không thấy tin nhắn đã gửi trên muse.ai. Xem ảnh chụp ở màn hình Xác thực Muse.');
            continue;
        }
        // Kết quả của mã này (theo tiêu đề hoặc mã gần nhất phía trước), chưa được yêu cầu khác nhận.
        const match = media.find((m) => m.kind === task.kind && m.ready && m.code === task.code && !claimed.has(m.id));
        const chosen = match?.id ?? null;
        // Muse từ chối (thấy qua 2 lần quét liên tiếp, không có kết quả): báo ngay, không chờ hết giờ.
        const refusal = !match && refusals.find((reply) => reply.codes.includes(task.code));
        task.refusalSeen = refusal ? (task.refusalSeen ?? 0) + 1 : 0;
        if (refusal && task.refusalSeen >= 2) {
            task.state = 'rejected';
            task.error = refusal.text.slice(0, 800);
            console.log(`[task ${task.code}] bị muse.ai từ chối`);
            continue;
        }
        // Đợi đứng yên qua 2 lần quét (muse.ai có thể hiện bản xem trước).
        if (chosen && chosen === task.candidate) {
            claimed.add(chosen);
            try {
                task.result =
                    match.kind === 'video'
                        ? { ...(await downloadVideo(p, match.id)), url: null }
                        : { ...(await download(p, match.src)), url: match.src.startsWith('blob:') ? null : match.src };
                task.result.matched = match.byTitle ? 'title' : 'nearest_code';
                task.state = 'done';
                console.log(`[task ${task.code}] xong sau ${Math.round((Date.now() - task.sentAt) / 1000)}s (${task.result.matched})`);
            } catch (error) {
                claimed.delete(chosen);
                fail(task, `Tải kết quả lỗi: ${error.message}`);
            }
        } else {
            task.candidate = chosen;
        }
    }
    // Dọn yêu cầu đã xong mà không ai lấy sau 1 giờ.
    for (const task of tasks.values()) if (task.state !== 'pending' && Date.now() - task.sentAt > 3600000) tasks.delete(task.code);
}

function fail(task, message) {
    task.state = 'failed';
    task.error = message;
    console.log(`[task ${task.code}] lỗi: ${message}`);
}

function publicTask(task, consume) {
    const body = { code: task.code, kind: task.kind, state: task.state, error: task.error ?? null };
    if (task.state === 'done') body.result = task.result;
    // Đã trả kết quả hoặc lỗi cho Laravel: xoá khỏi bộ nhớ.
    if (consume && task.state !== 'pending') tasks.delete(task.code);
    return body;
}

function getTask(code) {
    const task = tasks.get(code);
    if (!task) {
        const error = new Error('Không có yêu cầu này (dịch vụ trình duyệt có thể đã khởi động lại).');
        error.status = 404;
        throw error;
    }
    return publicTask(task, true);
}

// ---- Đăng nhập Claude (claude setup-token) ----
// setup-token cần terminal: chạy qua `script` để có pseudo-TTY, đọc link đăng nhập rồi gửi mã người dùng dán vào.

let claudeLogin = null;

function stripAnsi(text) {
    return text
        .replace(/\x1b\]8;[^\x07]*\x07/g, '') // hyperlink OSC 8
        .replace(/\x1b\][^\x07]*\x07/g, '')
        .replace(/\x1b\[[0-9;?>]*[A-Za-z]/g, ' ')
        .replace(/\x1b[78]/g, '')
        .replace(/\r/g, '');
}

function waitFor(login, test, timeoutMs) {
    return new Promise((resolve, reject) => {
        const started = Date.now();
        const timer = setInterval(() => {
            let found;
            try {
                found = test(login.output);
            } catch (error) {
                clearInterval(timer);
                reject(error);
                return;
            }
            if (found) {
                clearInterval(timer);
                resolve(found);
            } else if (login.exited || Date.now() - started > timeoutMs) {
                clearInterval(timer);
                // Không đưa output vào thông báo lỗi: có thể chứa token.
                reject(new Error(login.exited ? 'claude setup-token đã dừng mà không thấy token.' : 'Hết thời gian chờ claude setup-token.'));
            }
        }, 300);
    });
}

function stopClaudeLogin() {
    if (claudeLogin && !claudeLogin.exited) claudeLogin.child.kill('SIGKILL');
    claudeLogin = null;
}

async function startClaudeLogin() {
    stopClaudeLogin();
    // Cột rộng để link và token không bị xuống dòng.
    const child = spawn('script', ['-qfc', 'stty cols 2000 rows 50; claude setup-token', '/dev/null'], {
        env: { ...process.env, COLUMNS: '2000', LINES: '50', BROWSER: 'false' },
    });
    // Giả lập terminal để đọc đúng màn hình: giao diện CLI chỉ vẽ lại ký tự thay đổi
    // (nhảy con trỏ qua ký tự đã có), nên đọc thẳng output thô có thể mất ký tự của token.
    const term = new xterm.Terminal({ cols: 2000, rows: 50, allowProposedApi: true });
    const login = { child, term, output: '', exited: false };
    const onData = (chunk) => {
        login.output += chunk.toString();
        term.write(chunk);
    };
    child.stdout.on('data', onData);
    child.stderr.on('data', onData);
    // Đợi terminal giả lập xử lý hết output rồi mới coi là đã dừng.
    child.on('exit', () => term.write('', () => (login.exited = true)));
    claudeLogin = login;
    // Tự huỷ nếu người dùng bỏ dở.
    setTimeout(() => claudeLogin === login && stopClaudeLogin(), 15 * 60 * 1000);

    const url = await waitFor(login, (out) => out.match(/https:\/\/claude\.(?:com|ai)\/[^\s\x07\x1b"]*oauth\/authorize\?[^\s\x07\x1b"]+/)?.[0], 30000);
    return { url };
}

/** Che token và mã trước khi ghi log. */
function redact(text, code = '') {
    let out = text.replace(/sk-ant-[0-9A-Za-z_-]+/g, 'sk-ant-…REDACTED');
    if (code) out = out.split(code).join('…CODE…');
    return out;
}

/** Chữ đang hiển thị trên màn hình terminal giả lập (bỏ khoảng trắng cuối dòng). */
function screenText(term) {
    const buffer = term.buffer.active;
    const lines = [];
    for (let i = 0; i < buffer.length; i++) {
        lines.push(buffer.getLine(i)?.translateToString(true) ?? '');
    }
    return lines.join('\n');
}

function findToken(term) {
    return screenText(term).match(/sk-ant-oat[0-9A-Za-z_-]{20,}/)?.[0] ?? null;
}

/**
 * Gửi mã rồi trả về ngay; đổi mã lấy token chạy nền (Laravel hỏi lại qua /claude/login/status),
 * để request web không bị nginx cắt (504).
 */
async function submitClaudeCode(code) {
    const login = claudeLogin;
    if (!login || login.exited) {
        const error = new Error('Phiên đăng nhập Claude đã hết, bấm "Bắt đầu đăng nhập" lại.');
        error.status = 409;
        throw error;
    }
    code = code.trim();
    const before = login.output.length;
    login.phase = 'exchanging';
    login.result = null;

    // Gõ mã, đợi rồi mới Enter: gửi chung một lần thì ô nhập coi cả "\r" là nội dung dán vào.
    login.child.stdin.write(code);
    setTimeout(() => login.exited || login.child.stdin.write('\r'), 700);

    waitFor(
        login,
        (out) => {
            const token = findToken(login.term);
            if (token) return token;
            const text = stripAnsi(out.slice(before));
            if (/OAuth error|status code 4\d\d|invalid|expired/i.test(text)) {
                throw new Error('Claude báo lỗi: ' + redact(text, code).trim().replace(/\s+/g, ' ').slice(0, 300));
            }
            return null;
        },
        120000,
    )
        .then((token) => (login.result = { state: 'done', token }))
        .catch((error) => {
            console.log('[claude setup-token] ' + redact(error.message, code));
            console.log('[claude setup-token screen] ' + redact(screenText(login.term), code).replace(/\s+/g, ' ').slice(-1500));
            login.result = { state: 'error', message: error.message };
        })
        .finally(() => {
            login.phase = 'finished';
            if (!login.exited) login.child.kill('SIGKILL');
        });

    return { state: 'exchanging' };
}

/** Trạng thái đăng nhập Claude; token chỉ trả một lần rồi xoá khỏi bộ nhớ. */
function claudeLoginStatus() {
    const login = claudeLogin;
    if (!login) return { state: 'idle' };
    if (login.result) {
        const result = login.result;
        claudeLogin = null;
        return result;
    }
    return { state: login.phase ?? 'awaiting_code' };
}

async function readJson(req) {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    return chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {};
}

function send(res, code, body, type = 'application/json') {
    res.writeHead(code, { 'Content-Type': type });
    res.end(type === 'application/json' ? JSON.stringify(body) : body);
}

/** Chẩn đoán: cấu trúc ảnh và nút trên trang hiện tại (không chứa nội dung tin nhắn) để chỉnh bộ chọn khi muse.ai đổi giao diện. */
async function inspect() {
    const p = await getPage();
    const info = await p.evaluate(() => {
        const describe = (el) =>
            [el.tagName.toLowerCase(), el.getAttribute('role'), el.getAttribute('data-testid'), el.getAttribute('aria-label')]
                .filter(Boolean)
                .join('|');
        const chain = (el) => {
            const parts = [];
            for (let node = el.parentElement, i = 0; node && i < 8; node = node.parentElement, i++) parts.push(describe(node));
            return parts.join(' < ');
        };
        return {
            url: location.href,
            images: [...document.querySelectorAll('img')].map((img) => ({
                src: (img.currentSrc || img.src).slice(0, 140),
                size: `${img.naturalWidth}x${img.naturalHeight}`,
                alt: (img.alt || '').slice(0, 60),
                inLink: img.closest('a')?.getAttribute('href')?.slice(0, 100) ?? null,
                chain: chain(img),
            })),
            buttons: [...document.querySelectorAll('button, a[download], [role="button"]')]
                .map((b) => describe(b) + (b.textContent?.trim() ? ` "${b.textContent.trim().slice(0, 30)}"` : ''))
                .filter((s) => s.length > 6)
                .slice(0, 80),
            videos: [...document.querySelectorAll('video')].map((video) => ({
                src: (video.currentSrc || video.src || video.querySelector('source')?.src || '').slice(0, 140),
                size: `${video.videoWidth}x${video.videoHeight}`,
                duration: video.duration,
                chain: chain(video),
            })),
            busy: [...document.querySelectorAll('[aria-busy="true"], [role="progressbar"]')].map(describe),
            downloads: [...document.querySelectorAll('button[aria-label="Tải video xuống"], button[aria-label*="video" i]')].map((button) => {
                let box = button;
                for (let i = 0; i < 6 && box.parentElement; i++) box = box.parentElement;
                return { label: button.getAttribute('aria-label'), chain: chain(button), html: box.outerHTML.replace(/ (class|style|d)="[^"]*"/g, '').replace(/<svg.*?<\/svg>/g, '<svg/>').slice(0, 5000) };
            }),
            // Khung quanh các câu trả lời mang mã yêu cầu (chỉ cấu trúc thẻ, không lấy nội dung).
            replies: [...document.querySelectorAll('*')]
                .filter((el) => el.children.length === 0 && /^VA-[A-Z0-9]{6}$/.test(el.textContent.trim()))
                .map((el) => {
                    let box = el;
                    for (let i = 0; i < 8 && box.parentElement; i++) box = box.parentElement;
                    return {
                        code: el.textContent.trim(),
                        chain: chain(el),
                        html: box.outerHTML.replace(/ class="[^"]*"/g, "").replace(/ d="[^"]*"/g, "").replace(/>[^<]{40,}</g, ">…<").slice(0, 6000),
                    };
                }),
        };
    });
    // Kết quả quét giống lúc chờ yêu cầu: mã thấy trên trang, số kết quả theo mã, các lời từ chối.
    const scanned = await scan(p);
    info.scan = {
        anchors: scanned.anchors.length,
        media: scanned.media.filter((m) => m.code).map((m) => `${m.kind}:${m.code}:${m.byTitle ? 'title' : 'near'}:${m.ready}`),
        refusals: scanned.refusals.map((r) => ({ codes: r.codes, text: r.text.slice(0, 300) })),
        replies: [...(await p.locator('[data-hatch-assistant-message-body]').allInnerTexts())].slice(-6).map((t) => t.slice(0, 200)),
    };
    return info;
}

/** Lấy lại mọi ảnh muse.ai đã tạo đang hiển thị trong đoạn chat (cứu ảnh khi job trước lấy nhầm). */
async function generatedImages() {
    const p = await getPage();
    const found = await p.evaluate((selector) => {
        const seen = new Set();
        return [...document.querySelectorAll(selector)]
            .filter((img) => img.complete && img.naturalWidth >= 400 && !seen.has(img.currentSrc || img.src) && seen.add(img.currentSrc || img.src))
            .map((img) => ({ src: img.currentSrc || img.src, label: img.closest('button')?.getAttribute('aria-label') ?? '' }));
    }, GENERATED_IMAGES);
    const result = [];
    for (const image of found) result.push({ label: image.label, ...(await download(p, image.src)) });
    return result;
}

const routes = {
    'GET /inspect': () => serial(inspect),
    'GET /generated': () => serial(generatedImages),
    'GET /status': () => serial(() => refreshStatus(false)),
    'POST /status/refresh': () => serial(() => refreshStatus(true)),
    'POST /login': ({ email }) => serial(() => login(String(email ?? ''))),
    'POST /login/code': ({ code }) => serial(() => submitCode(String(code ?? '').replace(/\s/g, ''))),
    'POST /login/password': ({ password }) => serial(() => submitPassword(String(password ?? ''))),
    'POST /logout': () => serial(logout),
    'POST /tasks': ({ code, kind, prompt, images, timeout_ms, adopt }) =>
        serial(() =>
            sendTask({
                code: String(code ?? ''),
                kind: kind === 'video' ? 'video' : 'image',
                prompt: String(prompt ?? ''),
                images: Array.isArray(images) ? images : [],
                timeoutMs: Number(timeout_ms) || GENERATE_TIMEOUT_MS,
                adopt: adopt === true,
            }),
        ),
    // Không cần serial: không dùng trình duyệt.
    'POST /claude/login/start': () => startClaudeLogin(),
    'POST /claude/login/code': ({ code }) => submitClaudeCode(String(code ?? '')),
    'POST /claude/login/status': async () => claudeLoginStatus(),
    'POST /claude/login/cancel': async () => (stopClaudeLogin(), {}),
};

createServer(async (req, res) => {
    if (TOKEN && req.headers['x-token'] !== TOKEN) return send(res, 401, { message: 'Sai token.' });

    try {
        if (req.method === 'GET' && req.url === '/screenshot') {
            const png = await serial(async () => (await getPage()).screenshot());
            return send(res, 200, png, 'image/png');
        }
        const taskMatch = req.method === 'GET' && /^\/tasks\/(VA-[A-Z0-9]{6})$/.exec(req.url);
        if (taskMatch) return send(res, 200, getTask(taskMatch[1]));
        const handler = routes[`${req.method} ${req.url}`];
        if (!handler) return send(res, 404, { message: 'Không có route.' });
        const result = await handler(await readJson(req));
        const raw = ['/tasks', '/inspect', '/generated'].includes(req.url) || req.url.startsWith('/claude/');
        return send(res, 200, raw ? result : { ...status });
    } catch (error) {
        console.error(error);
        return send(res, error.status ?? 500, { message: error.message, status });
    }
}).listen(PORT, () => {
    console.log(`browser service on :${PORT}`);
    // Mở sẵn trình duyệt để biết trạng thái đăng nhập.
    serial(() => refreshStatus(true)).catch((e) => setStatus('error', `Không mở được muse.ai: ${e.message}`));
});
