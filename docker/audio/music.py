"""Phát nhạc Claude sáng tác cho tập phim: mỗi đoạn nhạc (cue) gồm nhịp độ, giai điệu và hợp âm từng ô nhịp 4/4.

Claude chỉ viết giai điệu + hợp âm; phần đệm (bass, pad, rải hợp âm, trống) do bộ phối khí dưới đây tạo theo phong cách,
để bản nhạc luôn đúng hoà âm. Ghi MIDI (mido) rồi FluidSynth phát bằng SoundFont FluidR3_GM (giấy phép MIT).
Đoạn nhạc lặp lại cho đủ thời lượng cần, cuối đoạn nhỏ dần.
"""

import os
import re
import subprocess
import tempfile

import mido
import numpy as np
import soundfile as sf

SOUNDFONT = os.environ.get("SOUNDFONT", "/usr/share/sounds/sf2/FluidR3_GM.sf2")
RATE = 44100
TICKS = 480
NOTE_NAMES = {"C": 0, "D": 2, "E": 4, "F": 5, "G": 7, "A": 9, "B": 11}
QUALITIES = {
    "": [0, 4, 7], "maj": [0, 4, 7], "m": [0, 3, 7], "min": [0, 3, 7], "7": [0, 4, 7, 10], "maj7": [0, 4, 7, 11],
    "m7": [0, 3, 7, 10], "dim": [0, 3, 6], "aug": [0, 4, 8], "sus2": [0, 2, 7], "sus4": [0, 5, 7], "6": [0, 4, 7, 9], "m6": [0, 3, 7, 9],
    "add9": [0, 4, 7, 14], "9": [0, 4, 7, 10, 14],
}

# Phong cách → cách đệm: bass (kiểu), pad (có / không), rải hợp âm, trống.
STYLES = {
    "playful": {"bass": "bounce", "pad": False, "arp": "staccato", "drums": "light"},
    "adventure": {"bass": "drive", "pad": True, "arp": "eighths", "drums": "march"},
    "mystery": {"bass": "long", "pad": True, "arp": "slow", "drums": None},
    "sneaky": {"bass": "pizz", "pad": False, "arp": "staccato", "drums": "tiptoe"},
    "tender": {"bass": "long", "pad": True, "arp": "slow", "drums": None},
    "calm": {"bass": "long", "pad": True, "arp": None, "drums": None},
    "tense": {"bass": "pulse", "pad": True, "arp": "eighths", "drums": "pulse"},
    "triumphant": {"bass": "drive", "pad": True, "arp": "eighths", "drums": "march"},
    "funny": {"bass": "bounce", "pad": False, "arp": "staccato", "drums": "light"},
}

ALLOWED_PROGRAMS = {
    "piano": 0, "bright_piano": 1, "celesta": 8, "glockenspiel": 9, "music_box": 10, "vibraphone": 11, "marimba": 12, "xylophone": 13,
    "accordion": 21, "harmonica": 22, "nylon_guitar": 24, "steel_guitar": 25, "pizzicato_strings": 45, "harp": 46, "strings": 48,
    "slow_strings": 49, "choir_aahs": 52, "trumpet": 56, "trombone": 57, "tuba": 58, "french_horn": 60, "brass": 61, "soprano_sax": 64,
    "clarinet": 71, "oboe": 68, "bassoon": 70, "flute": 73, "recorder": 74, "pan_flute": 75, "ocarina": 79, "warm_pad": 89, "kalimba": 108,
    "ukulele": 24, "synth_lead": 80,
}


def note_number(name):
    """'C#5' → số MIDI; 'R' → None (nghỉ)."""
    m = re.fullmatch(r"([A-Ga-g])([#b]?)(-?\d)", name.strip())
    if not m:
        return None
    pitch = NOTE_NAMES[m.group(1).upper()] + {"#": 1, "b": -1, "": 0}[m.group(2)]
    return max(24, min(108, 12 * (int(m.group(3)) + 1) + pitch))


def chord_notes(symbol):
    """'F#m7' → (gốc 0–11, các khoảng); hợp âm lạ thì trưởng."""
    m = re.fullmatch(r"([A-Ga-g])([#b]?)([^/]*)(?:/.*)?", symbol.strip())
    if not m:
        return 0, QUALITIES[""]
    root = (NOTE_NAMES[m.group(1).upper()] + {"#": 1, "b": -1, "": 0}[m.group(2)]) % 12
    return root, QUALITIES.get(m.group(3), QUALITIES["m" if m.group(3).startswith("m") and not m.group(3).startswith("maj") else ""])


def parse_melody(text):
    """'E5:1 G5:0.5 R:0.5' → [(note|None, beats)]."""
    events = []
    for token in str(text).split():
        if ":" not in token:
            continue
        name, beats = token.split(":", 1)
        try:
            length = max(0.125, min(8.0, float(beats)))
        except ValueError:
            continue
        events.append((None if name.upper() == "R" else note_number(name), length))
    return events


class Track:
    def __init__(self, channel, program):
        self.channel = channel
        self.program = program
        self.events = []  # (tick, on/off, note, velocity)

    def note(self, start_beat, beats, note, velocity):
        if note is None:
            return
        a = int(start_beat * TICKS)
        b = int((start_beat + max(0.05, beats)) * TICKS)
        self.events.append((a, 1, note, velocity))
        self.events.append((b, 0, note, 0))

    def to_midi(self):
        track = mido.MidiTrack()
        if self.channel != 9:
            track.append(mido.Message("program_change", channel=self.channel, program=self.program, time=0))
        now = 0
        for tick, on, note, velocity in sorted(self.events, key=lambda e: (e[0], e[1])):
            kind = "note_on" if on else "note_off"
            track.append(mido.Message(kind, channel=self.channel, note=note, velocity=velocity, time=tick - now))
            now = tick
        return track


def arrange(cue, total_beats):
    """Dựng các track MIDI cho một cue, lặp các ô nhịp đến đủ total_beats."""
    style = STYLES.get(cue.get("style"), STYLES["playful"])
    bars = [b for b in cue.get("bars", []) if b.get("chord")] or [{"chord": "C", "melody": "C5:4"}]
    lead = Track(0, ALLOWED_PROGRAMS.get(cue.get("lead"), 73))
    pad = Track(1, ALLOWED_PROGRAMS.get(cue.get("pad"), 48))
    bass = Track(2, 45 if style["bass"] == "pizz" else 32)
    arp = Track(3, ALLOWED_PROGRAMS.get(cue.get("arp"), 46))
    drums = Track(9, 0)
    bar_count = int(np.ceil(total_beats / 4))
    for i in range(bar_count):
        bar = bars[i % len(bars)]
        start = i * 4
        root, intervals = chord_notes(bar["chord"])
        # Giai điệu: đúng như Claude viết (cắt nếu quá 4 phách).
        beat = 0.0
        for note, length in parse_melody(bar.get("melody", "")):
            if beat >= 4:
                break
            lead.note(start + beat, min(length, 4 - beat) * 0.92, note, 92)
            beat += length
        chord = [48 + root + iv for iv in intervals]
        if style["pad"]:
            for n in chord:
                pad.note(start, 3.95, n, 52)
        low = 36 + root
        match style["bass"]:
            case "bounce":
                for b, n in ((0, low), (1, low + 7), (2, low), (3, low + 7)):
                    bass.note(start + b, 0.45, n, 82)
            case "drive":
                for b in range(8):
                    bass.note(start + b / 2, 0.4, low if b % 4 != 3 else low + 7, 80)
            case "pulse":
                for b in range(8):
                    bass.note(start + b / 2, 0.3, low, 70)
            case "pizz":
                for b, n in ((0, low + 12), (1.5, low + 19), (2, low + 12), (3.5, low + 19)):
                    bass.note(start + b, 0.3, n, 85)
            case _:
                bass.note(start, 3.9, low, 70)
        tones = [60 + root + iv for iv in intervals] + [72 + root]
        match style["arp"]:
            case "eighths":
                for b in range(8):
                    arp.note(start + b / 2, 0.45, tones[b % len(tones)], 55)
            case "staccato":
                for b in (0.5, 1.5, 2.5, 3.5):
                    for n in tones[:3]:
                        arp.note(start + b, 0.2, n, 50)
            case "slow":
                for b in range(4):
                    arp.note(start + b, 0.95, tones[b % len(tones)], 45)
        match style["drums"]:
            case "light":
                for b in range(4):
                    drums.note(start + b, 0.1, 36 if b % 2 == 0 else 38, 55 if b % 2 == 0 else 45)
                    drums.note(start + b + 0.5, 0.1, 42, 35)
            case "march":
                for b in range(4):
                    drums.note(start + b, 0.1, 36 if b in (0, 2) else 38, 80)
                    drums.note(start + b + 0.5, 0.1, 42, 45)
                if i % 4 == 3:
                    for k in range(4):
                        drums.note(start + 3 + k / 4, 0.1, 38, 70 + k * 8)
            case "pulse":
                for b in range(8):
                    drums.note(start + b / 2, 0.1, 42, 40)
                drums.note(start, 0.1, 36, 70)
            case "tiptoe":
                for b in (0, 2):
                    drums.note(start + b, 0.1, 75, 50)
                for b in (1, 3):
                    drums.note(start + b, 0.1, 76, 45)
    return [lead, pad, bass, arp, drums]


def render(cue, seconds):
    """Một cue → mảng float32 stereo 44,1 kHz dài đúng `seconds`, nhỏ dần ở cuối."""
    tempo = max(50, min(170, int(cue.get("tempo", 100))))
    total_beats = seconds * tempo / 60 + 2
    midi = mido.MidiFile(ticks_per_beat=TICKS)
    meta = mido.MidiTrack()
    meta.append(mido.MetaMessage("set_tempo", tempo=mido.bpm2tempo(tempo), time=0))
    midi.tracks.append(meta)
    for track in arrange(cue, total_beats):
        midi.tracks.append(track.to_midi())
    with tempfile.TemporaryDirectory() as tmp:
        mid, wav = os.path.join(tmp, "cue.mid"), os.path.join(tmp, "cue.wav")
        midi.save(mid)
        subprocess.run(["fluidsynth", "-ni", "-g", "0.6", "-r", str(RATE), "-F", wav, SOUNDFONT, mid], check=True, capture_output=True)
        audio, _ = sf.read(wav, dtype="float32", always_2d=True)
    need = int(seconds * RATE)
    if len(audio) < need:
        audio = np.concatenate([audio, np.zeros((need - len(audio), audio.shape[1]), dtype=np.float32)])
    audio = audio[:need]
    fade = min(need, int(1.2 * RATE))
    if fade > 0:
        audio[-fade:] *= np.linspace(1.0, 0.0, fade, dtype=np.float32)[:, None]
    attack = min(need, int(0.05 * RATE))
    if attack > 0:
        audio[:attack] *= np.linspace(0.0, 1.0, attack, dtype=np.float32)[:, None]
    # Chuẩn hoá về đỉnh 0,8 để mọi đoạn nhạc to ngang nhau (trộn nhỏ dưới lời thoại khi ghép tập).
    peak = float(np.max(np.abs(audio))) or 1.0
    return audio * (0.8 / peak)


def score(body):
    """{"cues": [{"cue": {...}, "seconds": 12.5}]} → mỗi cue một file WAV (base64)."""
    import base64

    out = []
    for item in body.get("cues", []):
        audio = render(item["cue"], max(0.5, min(600.0, float(item["seconds"]))))
        with tempfile.TemporaryDirectory() as tmp:
            path = os.path.join(tmp, "cue.wav")
            sf.write(path, audio, RATE, subtype="PCM_16")
            with open(path, "rb") as f:
                out.append({"base64": base64.b64encode(f.read()).decode(), "duration": round(len(audio) / RATE, 3)})
    return {"cues": out}
