# Text → 16:9 PNG mini‑app

This mini‑app takes your text (split into 50‑character chunks) and renders one or more 16:9 PNG images with a grey or custom background. An optional "Source" appears at the bottom‑right in parentheses.

- UI: `index.php` (Tailwind via CDN)
- Image generation: `generate.php` (PHP GD)
- Assets: `assets/background.(png|jpg|jpeg)`, optional `assets/font.ttf`

## Run on macOS using Laravel Herd

1. Install Laravel Herd
   - Download and install from the official docs: [herd.laravel.com/docs/1/getting-started/installation](https://herd.laravel.com/docs/1/getting-started/installation/)
   - Launch Herd once to finish setup (installs PHP, Nginx, etc.).

2. Put or link this project into Herd
   - Option A (move/copy): Place this project under Herd’s Sites directory (default is `~/Herd`).
   - Option B (link an existing folder): In Terminal, cd into the project and link it:
     ```bash
     cd /path/to/your/project
     herd link
     ```
     This exposes the project at `http://<folder-name>.test`.

3. Verify PHP GD is enabled
   - Herd ships PHP with common extensions. Confirm GD:
     ```bash
     php -m | grep -i gd
     ```
     If you see `gd`, you’re good. If not, switch PHP versions in Herd or enable GD in Herd’s settings.

4. Open the site
   - Visit `http://<folder-name>.test` in your browser (e.g., `http://text-to-png.test`).
   - The app entry point is `index.php` in the project root.

## How to use

- Enter your main text. It will be split into 50‑character chunks, one image per chunk.
- Optional "Source" is rendered bottom‑right as `(Your Source)`.
- Click Generate to preview, then Download PNG per image.

## Customize background and font

- Replace `assets/background.png` (or `.jpg/.jpeg`) to change the background.
- Optionally add a TrueType font at `assets/font.ttf`. If not present, the app tries common system fonts (e.g., DejaVu Sans).

## Notes

- Output size is 1600×900 (16:9). Text size auto‑fits.
- If you see "No TTF font found" errors, add `assets/font.ttf` or install a system TTF (e.g., DejaVu Sans) and restart.
- You can rename the linked domain in Herd’s UI (Sites) or by re‑running `herd link` from a differently named folder.