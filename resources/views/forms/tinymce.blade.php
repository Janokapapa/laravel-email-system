{{-- email-system::forms.tinymce --}}
@php
    // Safe initial HTML -> base64 (server is UTF-8)
    $initial = base64_encode((string) $getState());
    // Optional height via extraAttributes (default 300)
    $height = data_get($getExtraAttributes(), 'height', 300);
    // Optional max-width for email preview mode
    $maxWidth = data_get($getExtraAttributes(), 'maxWidth', null);
@endphp

<div
    wire:ignore
    x-data="tinymceField({
        state: @entangle($getStatePath()).live,
        id: '{{ $getId() }}',
        initial: '{{ $initial }}',
        height: {{ (int) $height }},
        maxWidth: {{ $maxWidth ? (int) $maxWidth : 'null' }},
    })"
    x-init="init()"
    x-on:tinymce:reinit.window="reinit()"
    class="fi-fo-field-wrp"
>
    @if ($getLabel())
        <label class="fi-fo-field-wrp-label">{{ $getLabel() }}</label>
    @endif

    <textarea
        :id="id"
        x-ref="ta"
        x-model="state"
        class="fi-input w-full"
        style="min-height: {{ (int) $height }}px; min-width: 100%;"
    ></textarea>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        // --- One-time TinyMCE loader (robust) ---
        if (!window.__tinyLoader) {
            window.__tinyLoader = new Promise((resolve, reject) => {
                if (window.tinymce?.init) return resolve();
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js';
                s.referrerPolicy = 'origin';
                s.onload = () => window.tinymce?.init ? resolve() : reject(new Error('TinyMCE loaded, but init missing'));
                s.onerror = () => reject(new Error('Failed to load TinyMCE'));
                document.head.appendChild(s);
            });
        }

        // UTF-8 safe base64 decode
        function b64ToUtf8(b64) {
            try {
                const bin = atob(b64 ?? '');
                const bytes = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                try { return new TextDecoder('utf-8').decode(bytes); }
                catch { return decodeURIComponent(Array.from(bytes, b => '%' + b.toString(16).padStart(2, '0')).join('')); }
            } catch { return ''; }
        }

        Alpine.data('tinymceField', ({ state, id, initial, height, maxWidth }) => ({
            state, id, initialDecoded: '', height: Number(height) || 300, maxWidth: maxWidth || null,
            editor: null, _typingTimer: null, _saveGuardsBound: false,

            async init() {
                this.initialDecoded = b64ToUtf8(initial);

                // Re-init on Livewire SPA navigations
                document.addEventListener('livewire:navigated', () => {
                    window.dispatchEvent(new CustomEvent('tinymce:reinit'));
                });

                // Wait for TinyMCE script AND visibility
                await window.__tinyLoader;
                await this.waitUntilVisible(this.$refs.ta);
                this.mount();
            },

            getThemeOptions() {
                const isDark = document.documentElement.classList.contains('dark');
                return { skin: isDark ? 'oxide-dark' : 'oxide', content_css: isDark ? 'dark' : 'default' };
            },

            mount() {
                // Remove any previous instance
                const prev = window.tinymce?.get?.(this.$refs.ta.id);
                if (prev) prev.remove();

                if (!window.tinymce || !window.tinymce.init) {
                    console.error('TinyMCE not loaded yet.');
                    return;
                }

                const theme = this.getThemeOptions();

                // Build content_style - use email layout styles when maxWidth is set
                let contentStyle = 'body { font-family: Arial, sans-serif; }';
                if (this.maxWidth) {
                    contentStyle = `
                        html { background: #edf2f7; }
                        body {
                            max-width: ${this.maxWidth}px;
                            margin: 20px auto;
                            padding: 32px;
                            background: #fff;
                            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                            color: #718096;
                            line-height: 1.4;
                            border-radius: 2px;
                            box-shadow: 0 2px 0 rgba(0, 0, 150, 0.025), 2px 4px 0 rgba(0, 0, 150, 0.015);
                        }
                        img { max-width: 100%; height: auto; }
                    `;
                }

                window.tinymce.init({
                    target: this.$refs.ta,
                    base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
                    suffix: '.min',
                    height: this.height,
                    content_style: contentStyle,

                    plugins: 'advlist autolink lists link image charmap preview anchor ' +
                        'searchreplace visualblocks code fullscreen insertdatetime media table ' +
                        'help wordcount emoticons codesample directionality visualchars nonbreaking',

                    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor ' +
                        '| alignleft aligncenter alignright alignjustify | outdent indent | bullist numlist checklist ' +
                        '| link image media table | hr charmap emoticons codesample | removeformat ' +
                        '| visualblocks visualchars nonbreaking ltr rtl | fullscreen preview | code help',

                    menubar: 'file edit view insert format tools table help',
                    toolbar_mode: 'wrap',
                    branding: false,
                    promotion: false,

                    // Dark/Light
                    skin: theme.skin,
                    content_css: theme.content_css,

                    setup: (editor) => {
                        this.editor = editor;

                        editor.on('init', () => {
                            const startHtml = (typeof this.state === 'string' && this.state.length)
                                ? this.state
                                : this.initialDecoded;
                            editor.setContent(startHtml || '');
                            editor.save();
                            this.bindSaveGuards();
                        });

                        // Throttled live sync while typing
                        const throttled = () => {
                            clearTimeout(this._typingTimer);
                            this._typingTimer = setTimeout(() => this.syncNow(), 250);
                        };
                        editor.on('Change KeyUp Undo Redo Input NodeChange', throttled);
                        editor.on('blur', () => this.syncNow());
                    },
                });
            },

            syncNow() {
                if (!this.editor) return;
                const html = this.editor.getContent();
                if (this.state !== html) this.state = html;
                try { this.editor.save(); } catch(_) {}
            },

            bindSaveGuards() {
                if (this._saveGuardsBound) return;
                this._saveGuardsBound = true;

                const formRoot = this.$root.closest('form') ?? document;

                const clickSync = (e) => {
                    const el = e.target.closest('[wire\\:click],[x-on\\:click],[data-action],button[type="submit"],[type="submit"]');
                    if (el) this.syncNow();
                };
                formRoot.addEventListener('click', clickSync, true);

                if (formRoot instanceof HTMLFormElement) {
                    formRoot.addEventListener('submit', () => this.syncNow(), true);
                }
            },

            reinit() {
                if (this.editor) { try { this.editor.remove(); } catch(_) {} this.editor = null; }
                this.waitUntilVisible(this.$refs.ta).then(() => this.mount());
            },

            waitUntilVisible(el, tries = 30) {
                return new Promise((resolve, reject) => {
                    const ok = () => el && el.isConnected && el.offsetParent !== null;
                    const tick = () => {
                        if (ok()) return resolve();
                        if (tries-- <= 0) return reject(new Error('TinyMCE target never became visible'));
                        setTimeout(tick, 100);
                    };
                    tick();
                });
            },
        }));
    });
</script>
