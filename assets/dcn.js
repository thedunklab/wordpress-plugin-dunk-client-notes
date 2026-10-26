/**
 * DUNK Client Notes: interfaccia sul sito (puntine, pannello, selezione dell'elemento, screenshot automatico).
 * Vanilla JS; l'interfaccia vive in uno Shadow DOM. Configurazione: window.dcnConfig (stampata dal plugin).
 */
(() => {
    'use strict'
    const cfg = window.dcnConfig
    if (!cfg || window.__dcn) return
    window.__dcn = true

    const normPath = (p) => (p.endsWith('/') ? p : p + '/')
    const path = normPath(location.pathname)
    const state = { notes: [], all: null, tab: 'page', view: 'list', ready: null, contacts: [], current: 0, resolved: false, picking: false, composer: null }

    /* ---------- utilità ---------- */
    const h = (tag, attrs = {}, ...kids) => {
        const el = document.createElement(tag)
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') el.className = v
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v)
            else if (v === true) el.setAttribute(k, '')
            else if (v !== false && v != null) el.setAttribute(k, v)
        }
        kids.flat().forEach((c) => c != null && el.append(c.nodeType ? c : document.createTextNode(String(c))))
        return el
    }
    const api = async (route, opts = {}) => {
        const res = await fetch(cfg.rest + route, {
            method: opts.method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-DCN-Token': cfg.token, 'X-WP-Nonce': cfg.nonce },
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(json.message || 'Errore di rete')
        return json
    }
    const STATUS = { open: 'Aperta', progress: 'In lavorazione', resolved: 'Risolta' }
    const savedName = () => { try { return localStorage.getItem('dcn_name') || '' } catch { return '' } }
    const saveName = (n) => { try { localStorage.setItem('dcn_name', n) } catch { /* nessun salvataggio */ } }

    /* ---------- radice (Shadow DOM) ---------- */
    const host = h('div', { id: 'dcn-root' })
    host.style.cssText = 'position:fixed;inset:0;z-index:2147483000;pointer-events:none'
    const shadow = host.attachShadow({ mode: 'open' })
    const root = h('div', { class: 'dcn' })
    shadow.append(h('style', {}, cfg.css), root)
    // i @font-face dentro uno Shadow DOM non vengono caricati: vanno nel documento (il nome del font vale anche dentro)
    if (cfg.font && !document.getElementById('dcn-font')) {
        document.head.append(h('style', { id: 'dcn-font' }, `@font-face{font-family:'DM Sans';font-style:normal;font-weight:100 1000;font-display:swap;src:url('${cfg.font}') format('woff2');}`))
    }

    const pinsLayer = h('div')
    const hl = h('div', { class: 'hl', hidden: true })
    const fab = h('button', { class: 'fab', type: 'button', 'aria-label': 'Note del cliente', onclick: () => togglePanel() })
    const panel = h('div', { class: 'panel', hidden: true, role: 'dialog', 'aria-label': 'Note del cliente' })
    const overlay = h('div') // composer, hint, toast
    root.append(pinsLayer, hl, panel, fab, overlay)

    // cursore a croce durante la selezione (sull'intera pagina)
    const pickStyle = h('style', {}, 'html.dcn-picking, html.dcn-picking * { cursor: crosshair !important; }')

    const toast = (msg) => {
        const t = h('div', { class: 'toast' }, msg)
        overlay.append(t)
        setTimeout(() => t.remove(), 3200)
    }

    /* ---------- selettore CSS dell'elemento ---------- */
    const cssPath = (el) => {
        const parts = []
        while (el && el.nodeType === 1 && el !== document.documentElement) {
            if (el.id && /^[A-Za-z][\w-]*$/.test(el.id) && document.querySelectorAll('#' + CSS.escape(el.id)).length === 1) {
                parts.unshift('#' + CSS.escape(el.id))
                break
            }
            const tag = el.tagName.toLowerCase()
            const sibs = [...el.parentElement.children].filter((c) => c.tagName === el.tagName)
            parts.unshift(sibs.length > 1 ? `${tag}:nth-of-type(${sibs.indexOf(el) + 1})` : tag)
            if (tag === 'body') break
            el = el.parentElement
        }
        return parts.join(' > ')
    }
    const safeQuery = (sel) => { try { return sel ? document.querySelector(sel) : null } catch { return null } }

    /* ---------- posizione delle puntine ---------- */
    const pointFor = (n) => {
        const el = safeQuery(n.selector)
        if (el) {
            const r = el.getBoundingClientRect()
            if (r.width > 0 && r.height > 0) return { x: r.left + n.rx * r.width, y: r.top + n.ry * r.height, exact: true }
        }
        const docW = document.documentElement.scrollWidth
        return { x: n.px * docW - scrollX, y: n.py - scrollY, exact: false }
    }
    let raf = 0
    const schedule = () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; placePins() }) }
    const placePins = () => {
        pinsLayer.querySelectorAll('.pin').forEach((pin) => {
            const n = state.notes.find((x) => x.id === Number(pin.dataset.id))
            if (!n) return
            const p = pointFor(n)
            const visible = p.x > -40 && p.y > -10 && p.x < innerWidth + 40 && p.y < innerHeight + 40
            pin.style.display = visible ? '' : 'none'
            pin.style.left = Math.round(p.x) + 'px'
            pin.style.top = Math.round(p.y) + 'px'
            pin.classList.toggle('approx', !p.exact)
        })
    }
    addEventListener('scroll', schedule, { passive: true })
    addEventListener('resize', schedule)
    new ResizeObserver(schedule).observe(document.documentElement)

    /* ---------- caricamento ---------- */
    const load = async () => {
        try {
            state.all = await api('/notes')
            state.notes = state.all.filter((n) => n.path === path)
            state.contacts = await api('/contacts')
            if (cfg.ready) state.ready = await api('/ready?path=' + encodeURIComponent(path))
        } catch (e) { toast(e.message) }
        render()
    }

    /* ---------- render ---------- */
    const render = () => {
        renderFab()
        renderPins()
        renderPanel()
    }
    const visibleNotes = () => state.notes.filter((n) => state.resolved || n.status !== 'resolved')

    const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.5A8 8 0 1 1 21 12z"/></svg>'
    const renderFab = () => {
        // il numero è il totale delle note aperte in tutto il sito (nel tooltip anche quelle di questa pagina)
        const open = (state.all || state.notes).filter((n) => n.status !== 'resolved').length
        const here = state.notes.filter((n) => n.status !== 'resolved').length
        fab.title = `${open} not${open === 1 ? 'a aperta' : 'e aperte'} nel sito, ${here} in questa pagina`
        fab.replaceChildren(...[document.createRange().createContextualFragment(ICON), 'Note', open ? h('span', { class: 'badge' }, open) : null].filter(Boolean))
    }

    const renderPins = () => {
        pinsLayer.replaceChildren(
            ...visibleNotes().map((n) =>
                h('button', {
                    class: `pin ${n.status}${state.current === n.id && state.view === 'note' ? ' active' : ''}`,
                    type: 'button', 'data-id': n.id, title: n.text.slice(0, 80), 'aria-label': 'Nota ' + n.n,
                    onclick: () => openNote(n.id),
                }, h('span', {}, n.n))
            )
        )
        placePins()
    }

    const togglePanel = (open) => {
        const show = open ?? panel.hidden
        panel.hidden = !show
        if (show) { state.view = state.view === 'note' ? 'note' : 'list'; load() }
    }

    const numBadge = (n) => h('span', { class: `num ${n.status}` }, n.n)
    const statusBadge = (s) => h('span', { class: `st ${s}` }, STATUS[s])

    const renderPanel = () => {
        if (panel.hidden) return
        const head = h('div', { class: 'head' },
            state.view === 'note' ? h('button', { class: 'icon', type: 'button', 'aria-label': 'Indietro', onclick: () => { state.view = 'list'; renderPins(); renderPanel() } }, '←') : null,
            h('h2', {}, state.view === 'note' ? 'Nota' : state.view === 'contacts' ? 'Persone esterne' : `Note · ${cfg.label}`),
            h('button', { class: 'icon', type: 'button', 'aria-label': 'Chiudi', onclick: () => { panel.hidden = true } }, '✕')
        )
        const body = h('div', { class: 'body' })
        panel.replaceChildren(...[head, state.view === 'note' ? null : toolbar(), body, byDunk()].filter(Boolean))
        state.view === 'note' ? renderNote(body) : state.view === 'contacts' ? renderContacts(body) : renderList(body)
    }

    const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700.9 334.8" fill="currentColor" aria-hidden="true"><path d="M140.5,19.1C129,6.4,111.7,0,88.8,0H0v235l64.8-8.8V56.2h7.1c6.2,0,10.9.6,13.9,1.9,3,1.2,5.1,3.7,6.2,7.3,1.1,3.6,1.7,9.1,1.7,16.3v140.6l65.1-8.8-.7-137.8c-.2-25-6.1-43.8-17.6-56.6h0Z"></path> <path d="M102.4,257.6c0,11.5-5.9,17.2-17.6,17.2h-11.2v-38.6l-64.8,8.8v86.8h86.5c26,0,44.6-8,55.8-24s16.7-39.8,16.5-71.5v-12.8c0,0-65.2,8.8-65.2,8.8v25.3h0Z"></path> <polygon points="236 0 173.4 0 173.4 211.5 236 203 236 0"></polygon> <polygon points="267.4 0 267.4 198.8 330 190.3 330 0 267.4 0"></polygon> <path d="M276.2,243c0,10.5-1,18.5-3,24.2s-6.2,8.4-12.7,8.4-10.7-2.9-12.7-8.6c-2-5.7-3-13.7-3-24v-30l-62.6,8.5v24.1c0,29.5,6.4,51.7,19.3,66.7,12.9,15,32.5,22.5,59,22.5s46.1-7.5,59-22.5,19.3-37.2,19.3-66.7v-45.3l-62.5,8.5v34.2h0Z"></path> <polygon points="451.1 331.8 514 331.8 514 176.5 422.5 188.9 451.1 331.8"></polygon> <polygon points="356 331.8 418.9 331.8 418.9 189.4 356 197.9 356 331.8"></polygon> <polygon points="443.1 0 443.1 153.9 413.5 0 347.2 0 347.2 187.9 410.1 179.4 410.1 160.7 413.8 178.9 505.3 166.5 505.3 0 443.1 0"></polygon> <polygon points="657.5 157.1 535 173.7 535 331.8 599 331.8 599 175.9 634.3 331.8 700.9 331.8 657.5 157.2 657.5 157.1"></polygon> <polygon points="684.7 0 619.9 0 589.9 128.9 589.9 0 526.3 0 526.3 163.7 648.8 147.1 684.7 0"></polygon></svg>'
    const byDunk = () => h('div', { class: 'by-dunk' }, h('a', { class: 'logo', href: 'https://thedunk.it', target: '_blank', rel: 'noopener', 'aria-label': 'DUNK' }, document.createRange().createContextualFragment(LOGO)))

    const USER_PLUS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>'

    /* persone esterne: si aggiungono qui (nome + email) e si richiamano per segnalare una nota o una risposta */
    const renderContacts = (body) => {
        const name = h('input', { type: 'text', placeholder: 'Nome e cognome', maxlength: 80, required: true, 'aria-label': 'Nome e cognome' })
        const email = h('input', { type: 'email', placeholder: 'Email', maxlength: 120, required: true, 'aria-label': 'Email' })
        const add = h('button', { class: 'btn primary add', type: 'submit', title: 'Aggiungi', 'aria-label': 'Aggiungi' }, '+')
        body.append(
            h('p', { class: 'hint-txt' }, 'Aggiungi chi vuoi coinvolgere fuori dal team: scrivendo una nota o una risposta potrai segnalarla a queste persone, che la riceveranno via email.'),
            h('form', { class: 'form contact-form', onsubmit: async (e) => {
                e.preventDefault()
                add.disabled = true
                try { state.contacts = await api('/contacts', { method: 'POST', body: { name: name.value, email: email.value } }); toast('Persona aggiunta.'); renderPanel() } catch (err) { toast(err.message); add.disabled = false }
            } }, name, h('div', { class: 'row' }, email, add))
        )
        if (!state.contacts.length) { body.append(h('div', { class: 'empty' }, 'Nessuna persona aggiunta.')); return }
        state.contacts.forEach((c) => body.append(h('div', { class: 'contact' },
            h('span', { class: 'avatar' }, c.name.trim().charAt(0).toUpperCase()),
            h('span', { class: 'contact-info' }, h('b', {}, c.name), h('span', { class: 'meta' }, c.email)),
            h('button', { class: 'icon', type: 'button', title: 'Rimuovi', 'aria-label': 'Rimuovi ' + c.name, onclick: async () => {
                try { state.contacts = await api('/contacts/' + c.id, { method: 'DELETE' }); renderPanel() } catch (err) { toast(err.message) }
            } }, '✕')
        )))
    }

    /* scelta delle persone esterne da segnalare (nel modulo di nota e in quello di risposta) */
    const tagPicker = () => {
        const chosen = new Set()
        if (!state.contacts.length) return { el: null, value: () => [] }
        const el = h('div', { class: 'tag' }, h('span', { class: 'tag-label' }, 'Segnala a'),
            ...state.contacts.map((c) => h('button', { class: 'chip', type: 'button', 'aria-pressed': 'false', title: c.email, onclick: (e) => {
                chosen.has(c.id) ? chosen.delete(c.id) : chosen.add(c.id)
                e.currentTarget.setAttribute('aria-pressed', String(chosen.has(c.id)))
            } }, c.name)))
        return { el, value: () => [...chosen] }
    }

    const toolbar = () => {
        const src = state.tab === 'all' ? (state.all || []) : state.notes
        const open = src.filter((n) => n.status !== 'resolved').length
        const done = src.length - open
        return h('div', { class: 'tools' },
            h('div', { class: 'tools-row' },
                h('button', { class: 'btn primary', type: 'button', onclick: startPick }, '+ Nuova nota'),
                h('div', { class: 'tools-right' },
                    h('div', { class: 'tabs', role: 'tablist' },
                        h('button', { class: 'tab', role: 'tab', 'aria-selected': String(state.tab === 'page' && state.view !== 'contacts'), onclick: () => { state.tab = 'page'; state.view = 'list'; renderPanel() } }, 'Pagina'),
                        h('button', { class: 'tab', role: 'tab', 'aria-selected': String(state.tab === 'all' && state.view !== 'contacts'), onclick: () => { state.tab = 'all'; state.view = 'list'; renderPanel() } }, 'Tutte')
                    ),
                    h('button', { class: `icon-btn${state.view === 'contacts' ? ' on' : ''}`, type: 'button', title: 'Persone esterne da segnalare', 'aria-label': 'Persone esterne da segnalare', 'aria-pressed': String(state.view === 'contacts'),
                        onclick: () => { state.view = state.view === 'contacts' ? 'list' : 'contacts'; renderPanel() } },
                        document.createRange().createContextualFragment(USER_PLUS))
                )
            ),
            h('div', { class: 'tools-row sub' },
                h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: state.resolved || false, onchange: (e) => { state.resolved = e.target.checked; render() } }), 'Mostra risolte'),
                h('span', { class: 'count' }, `${open} apert${open === 1 ? 'a' : 'e'}${done ? ` · ${done} risolt${done === 1 ? 'a' : 'e'}` : ''}`)
            ),
            readyBox()
        )
    }

    /* avviso «note pronte»: una sola email per pagina, non una per nota */
    const readyBox = () => {
        const open = state.notes.filter((n) => n.status === 'open').length
        if (!cfg.ready || state.tab !== 'page' || (!open && !state.ready?.sent)) return null
        const r = state.ready || { sent: false }
        const btn = h('button', { class: 'btn primary', type: 'button', disabled: !open, onclick: () => sendReady(btn) }, r.sent ? 'Invia di nuovo' : 'Segnala note pronte')
        return h('div', { class: 'ready' },
            h('div', { class: 'ready-txt' },
                r.sent ? h('b', {}, `Avviso inviato a ${r.to}`) : h('b', {}, 'Hai finito con questa pagina?'),
                r.sent ? h('span', {}, `${r.date} · ${r.count} not${r.count === 1 ? 'a' : 'e'}${r.fresh ? ` · ${r.fresh} nuov${r.fresh === 1 ? 'a' : 'e'} dopo l’invio` : ''}`) : h('span', {}, 'Avvisa il team che le note sono pronte da mettere in lavorazione.')),
            btn)
    }
    const sendReady = async (btn) => {
        const r = state.ready
        if (r?.sent && !r.fresh && !confirm('Non ci sono nuove note dall’ultimo avviso. Inviarlo di nuovo?')) return
        let name = cfg.user
        if (!cfg.team) {
            name = savedName() || (prompt('Il tuo nome') || '').trim()
            if (!name) return
            saveName(name)
        }
        btn.disabled = true
        try {
            state.ready = await api('/ready', { method: 'POST', body: { path, author: name } })
            toast('Avviso inviato.')
        } catch (e) { toast(e.message) }
        renderPanel()
    }

    const renderList = (body) => {
        const src = state.tab === 'all' ? (state.all || []) : state.notes
        const list = src.filter((n) => state.resolved || n.status !== 'resolved').slice().sort((a, b) => b.id - a.id)
        if (!list.length) {
            body.append(h('div', { class: 'empty' }, state.tab === 'all' ? 'Nessuna nota nel sito.' : 'Nessuna nota su questa pagina. Premi «Nuova nota», poi clicca sul punto da commentare.'))
            return
        }
        list.forEach((n) => body.append(h('button', { class: 'item', type: 'button', onclick: () => (n.path === path ? openNote(n.id) : (location.href = n.url ? `${n.url.split('#')[0]}#dcn-${n.id}` : `${n.path}#dcn-${n.id}`)) },
            numBadge(n),
            h('span', { class: 'item-body' },
                h('span', { class: 'txt' }, n.text),
                h('span', { class: 'item-meta' },
                    h('span', {}, `${n.author} · ${n.date}`, n.replies.length ? ` · ${n.replies.length} rispost${n.replies.length === 1 ? 'a' : 'e'}` : ''),
                    state.tab === 'all' ? h('code', { class: 'path' }, n.path) : null,
                    n.status !== 'open' ? statusBadge(n.status) : null)
            )
        )))
    }

    const openNote = (id) => {
        const n = state.notes.find((x) => x.id === id) || (state.all || []).find((x) => x.id === id)
        if (!n) return
        state.current = id
        state.view = 'note'
        panel.hidden = false
        const el = safeQuery(n.selector)
        if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' })
        renderPins()
        renderPanel()
    }

    const renderNote = (body) => {
        const n = state.notes.find((x) => x.id === state.current) || (state.all || []).find((x) => x.id === state.current)
        if (!n) { body.append(h('div', { class: 'empty' }, 'Nota non trovata.')); return }
        body.append(h('div', { class: 'note-card' },
            h('div', { class: 'by' }, numBadge(n),
                h('div', { class: 'by-info' },
                    h('div', { class: 'by-name' }, n.author, n.source === 'team' ? h('span', { class: 'meta' }, ' (team)') : null),
                    h('div', { class: 'by-meta' }, n.date, statusBadge(n.status)))),
            h('p', {}, n.text),
            n.tagged?.length ? h('p', { class: 'tagged' }, 'Segnalata a: ' + n.tagged.join(', ')) : null,
            n.shot ? h('a', { class: 'shot', href: n.shot, target: '_blank', rel: 'noopener' }, h('img', { src: n.shot, alt: 'Screenshot della pagina al momento della nota', loading: 'lazy' })) : null
        ))
        if (cfg.team) {
            body.append(h('div', { class: 'status-row' }, ...Object.entries(STATUS).map(([k, label]) =>
                h('button', { class: `btn${n.status === k ? ' primary' : ''}`, type: 'button', onclick: async () => {
                    try { await api(`/notes/${n.id}/status`, { method: 'POST', body: { status: k } }); await load(); } catch (e) { toast(e.message) }
                } }, label))
            ), h('p', { class: 'link' }, h('a', { href: `${cfg.admin}&note=${n.id}`, target: '_blank', rel: 'noopener' }, 'Apri in bacheca')))
        }
        n.replies.forEach((r) => body.append(h('div', { class: `reply${r.source === 'team' ? ' team' : ''}` }, h('b', {}, r.author), r.source === 'team' ? ' (team)' : '', h('span', { class: 'meta' }, ' · ' + r.date), h('p', {}, r.text), r.tagged?.length ? h('p', { class: 'tagged' }, 'Segnalata a: ' + r.tagged.join(', ')) : null)))
        const name = h('input', { type: 'text', placeholder: 'Il tuo nome', value: savedName(), maxlength: 60, required: !cfg.team, hidden: cfg.team, 'aria-label': 'Il tuo nome' })
        const text = h('textarea', { placeholder: 'Scrivi una risposta…', maxlength: 2000, required: true, 'aria-label': 'Risposta' })
        const send = h('button', { class: 'btn primary', type: 'submit' }, 'Rispondi')
        const tags = tagPicker()
        body.append(h('form', { class: 'form', onsubmit: async (e) => {
            e.preventDefault()
            send.disabled = true
            try {
                if (!cfg.team) saveName(name.value.trim())
                await api(`/notes/${n.id}/replies`, { method: 'POST', body: { text: text.value, author: name.value, tag: tags.value() } })
                await load()
            } catch (err) { toast(err.message); send.disabled = false }
        } }, name, text, tags.el, send))
    }

    /* ---------- nuova nota: scelta dell'elemento ---------- */
    const inRoot = (el) => el === host || host.contains(el)
    const target = (e) => { const t = e.target; return t && t.nodeType === 1 && !inRoot(t) && t !== document.documentElement ? t : null }

    const onMove = (e) => {
        const t = target(e)
        if (!t) { hl.hidden = true; return }
        const r = t.getBoundingClientRect()
        Object.assign(hl.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' })
        hl.hidden = false
    }
    const onClick = (e) => {
        const t = target(e)
        if (!t) return
        e.preventDefault(); e.stopPropagation()
        const r = t.getBoundingClientRect()
        const pick = {
            selector: cssPath(t),
            rx: r.width ? Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)) : 0.5,
            ry: r.height ? Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) : 0.5,
            px: (e.clientX + scrollX) / document.documentElement.scrollWidth,
            py: e.clientY + scrollY,
            cx: e.clientX, cy: e.clientY,
        }
        stopPick()
        openComposer(pick)
    }
    const onKey = (e) => { if (e.key === 'Escape') stopPick() }
    const swallow = (e) => { if (target(e)) { e.preventDefault(); e.stopPropagation() } }

    const hint = h('div', { class: 'hint' }, 'Clicca sull’elemento da commentare ', h('button', { type: 'button', onclick: () => stopPick() }, 'Annulla'))
    function startPick() {
        panel.hidden = true
        state.picking = true
        document.head.append(pickStyle)
        document.documentElement.classList.add('dcn-picking')
        overlay.append(hint)
        document.addEventListener('mousemove', onMove, true)
        document.addEventListener('click', onClick, true)
        document.addEventListener('mousedown', swallow, true)
        document.addEventListener('keydown', onKey, true)
    }
    function stopPick() {
        state.picking = false
        pickStyle.remove()
        document.documentElement.classList.remove('dcn-picking')
        hint.remove()
        hl.hidden = true
        document.removeEventListener('mousemove', onMove, true)
        document.removeEventListener('click', onClick, true)
        document.removeEventListener('mousedown', swallow, true)
        document.removeEventListener('keydown', onKey, true)
    }

    /* ---------- screenshot ---------- */
    async function capture(cx, cy) {
        if (typeof window.html2canvas !== 'function') return ''
        const scale = Math.min(1, 1280 / innerWidth)
        try {
            const canvas = await Promise.race([
                window.html2canvas(document.documentElement, {
                    x: scrollX, y: scrollY, width: innerWidth, height: innerHeight, scale,
                    useCORS: true, logging: false, backgroundColor: '#ffffff',
                    windowWidth: document.documentElement.clientWidth, windowHeight: innerHeight,
                    ignoreElements: (el) => el.id === 'dcn-root',
                }),
                new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 10000)),
            ])
            const ctx = canvas.getContext('2d')
            const x = cx * scale, y = cy * scale, r = 16 * scale
            ctx.lineWidth = Math.max(3, 4 * scale)
            ctx.strokeStyle = '#0a0a0a'
            ctx.fillStyle = 'rgba(254,224,0,.55)'
            ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill(); ctx.stroke()
            return canvas.toDataURL('image/jpeg', 0.72)
        } catch { return '' }
    }

    /* ---------- composer ---------- */
    function openComposer(pick) {
        closeComposer()
        const name = h('input', { type: 'text', placeholder: 'Il tuo nome', value: cfg.team ? cfg.user : savedName(), maxlength: 60, required: true, hidden: cfg.team, 'aria-label': 'Il tuo nome' })
        const text = h('textarea', { placeholder: 'Cosa vuoi segnalare o modificare?', maxlength: 2000, required: true, 'aria-label': 'Nota' })
        const shot = h('input', { type: 'checkbox', checked: true })
        const send = h('button', { class: 'btn primary', type: 'submit' }, 'Invia nota')
        const tags = tagPicker()
        const form = h('form', { class: 'composer', onsubmit: async (e) => {
            e.preventDefault()
            send.disabled = true; send.textContent = 'Invio…'
            if (!cfg.team) saveName(name.value.trim())
            try {
                let screenshot = ''
                if (shot.checked) {
                    form.hidden = true // fuori dallo screenshot
                    screenshot = await capture(pick.cx, pick.cy)
                    form.hidden = false
                }
                const note = await api('/notes', { method: 'POST', body: {
                    path, url: location.href.split('#')[0], selector: pick.selector, rx: pick.rx, ry: pick.ry, px: pick.px, py: pick.py,
                    vw: innerWidth, text: text.value, author: name.value, screenshot, tag: tags.value(),
                } })
                closeComposer()
                toast('Nota inviata. Grazie!')
                await load()
                openNote(note.id)
            } catch (err) { form.hidden = false; toast(err.message); send.disabled = false; send.textContent = 'Invia nota' }
        } },
            h('h3', {}, 'Nuova nota'), name, text, tags.el,
            h('label', { class: 'check' }, shot, 'Allega uno screenshot della pagina'),
            h('div', { class: 'row' }, send, h('button', { class: 'btn', type: 'button', onclick: closeComposer }, 'Annulla'))
        )
        const w = 320
        form.style.left = Math.max(12, Math.min(innerWidth - w - 12, pick.cx + 14)) + 'px'
        form.style.top = Math.max(12, Math.min(innerHeight - 300, pick.cy + 14)) + 'px'
        // segnaposto provvisorio nel punto scelto
        state.composer = { form, pick }
        overlay.append(form)
        text.focus()
    }
    function closeComposer() { state.composer?.form.remove(); state.composer = null }

    /* ---------- avvio ---------- */
    document.body.append(host)
    render()
    load().then(() => {
        const m = location.hash.match(/^#dcn-(\d+)$/)
        if (m) openNote(Number(m[1]))
    })
})()
