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
    const stored = (k) => { try { return localStorage.getItem(k) || '' } catch { return '' } }
    const store = (k, v) => { try { localStorage.setItem(k, v) } catch { /* nessun salvataggio */ } }
    const savedName = () => stored('dcn_name')
    // chiavi per correggere ciò che si è scritto da questo browser (id nota/risposta → chiave)
    const keys = () => { try { return JSON.parse(stored('dcn_keys') || '{}') } catch { return {} } }
    const saveKey = (id, key) => { if (!key) return; const k = keys(); k[id] = key; store('dcn_keys', JSON.stringify(k)) }

    /* gruppi di lavoro (Impostazioni): colore delle puntine */
    const GROUPS = cfg.groups || []
    const groupOf = (id) => GROUPS.find((g) => g.id === id) || null
    const groupTint = (el, id) => { const g = groupOf(id); if (g) { el.classList.add('g'); el.style.setProperty('--g', g.color); el.style.setProperty('--g-ink', g.ink) } return el }
    const groupChip = (id) => { const g = groupOf(id); return g ? h('span', { class: 'grp', style: `--g:${g.color};--g-ink:${g.ink}` }, g.name) : null }

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

    const inRoot = (el) => el === host || host.contains(el)

    const pinsLayer = h('div')
    const hl = h('div', { class: 'hl', hidden: true })
    const fab = h('button', { class: 'fab', type: 'button', 'aria-label': 'Note del cliente', onclick: () => togglePanel() })
    if (cfg.fab?.side === 'left') Object.assign(fab.style, { left: '20px', right: 'auto' })
    if (cfg.fab?.bottom > 0) { fab.style.bottom = cfg.fab.bottom + 'px'; root.style.setProperty('--panel-b', (cfg.fab.bottom + 60) + 'px') }
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

    /* ---------- ancoraggio robusto all'elemento ----------
     * Un percorso CSS con nth-of-type si sposta quando si toglie o si aggiunge un elemento vicino: la puntina finirebbe
     * sull'elemento sbagliato. Per questo alla nota si salvano anche l'«impronta» dell'elemento (tag, id, classi stabili,
     * attributi, testo, antenati, fratelli) e un selettore semantico; quando si ripristina la puntina il candidato va
     * sempre confrontato con l'impronta e, se non combacia, l'elemento si cerca per somiglianza. Se non esiste più,
     * la nota resta «non agganciata» (posizione approssimata) invece di spostarsi su un altro elemento. */
    const norm = (t) => (t || '').replace(/\s+/g, ' ').trim().toLowerCase()
    const textOf = (el) => (el ? norm(el.textContent).slice(0, 80) : '')
    const STATE_CLS = /^(is-|has-|js-|active$|open$|show$|shown$|hover|focus|current|selected|swiper|slick|aos|dcn|ready$|loaded$|visible$|hidden$)/i
    const stableCls = (c) => c && c.length < 40 && !STATE_CLS.test(c) && !/\d{3,}/.test(c)
    const classesOf = (el) => [...el.classList].filter(stableCls).slice(0, 6)
    const validId = (el) => el.id && /^[A-Za-z][\w-]*$/.test(el.id) && !/\d{4,}/.test(el.id)
    const fileOf = (v) => (v || '').split('#')[0].split('?')[0].split('/').filter(Boolean).pop() || ''
    const ATTRS = ['href', 'src', 'alt', 'name', 'aria-label', 'title', 'placeholder', 'for', 'type', 'role']
    const attrsOf = (el) => {
        const o = {}
        ATTRS.forEach((k) => {
            const v = el.getAttribute(k)
            if (v) o[k] = (k === 'href' || k === 'src') ? fileOf(v).slice(0, 80) : norm(v).slice(0, 80)
        })
        return o
    }
    const ancestorsOf = (el) => {
        const out = []
        for (let p = el.parentElement; p && p !== document.body && out.length < 4; p = p.parentElement) out.push({ tag: p.tagName.toLowerCase(), id: validId(p) ? p.id : '', cls: classesOf(p).slice(0, 4) })
        return out
    }
    const fingerprint = (el) => ({
        tag: el.tagName.toLowerCase(), id: validId(el) ? el.id : '', cls: classesOf(el), attrs: attrsOf(el), text: textOf(el),
        anc: ancestorsOf(el), prev: textOf(el.previousElementSibling), next: textOf(el.nextElementSibling),
    })
    // selettore semantico: id, oppure tag + classi stabili, risalendo gli antenati finché è unico (niente nth-of-type)
    const stableSelector = (el) => {
        const unique = (q) => { try { return document.querySelectorAll(q).length === 1 } catch { return false } }
        const part = (e) => (validId(e) ? '#' + CSS.escape(e.id) : e.tagName.toLowerCase() + classesOf(e).slice(0, 3).map((c) => '.' + CSS.escape(c)).join(''))
        const parts = []
        for (let cur = el, i = 0; cur && cur !== document.documentElement && i < 5; cur = cur.parentElement, i++) {
            parts.unshift(part(cur))
            const q = parts.join(' > ')
            if (unique(q)) return q
            if (validId(cur) || cur === document.body) break
        }
        return ''
    }
    // somiglianza fra un elemento e l'impronta salvata: 0..1 (solo sui dati che l'impronta contiene)
    const similarity = (el, fp) => {
        if (el.tagName.toLowerCase() !== fp.tag) return 0
        let got = 0, max = 0
        const add = (w, ok) => { max += w; if (ok) got += w }
        if (fp.id) add(8, el.id === fp.id)
        if (fp.cls.length) { const c = classesOf(el); add(4, false); got += 4 * fp.cls.filter((x) => c.includes(x)).length / Math.max(fp.cls.length, c.length) }
        const at = attrsOf(el)
        Object.entries(fp.attrs || {}).forEach(([k, v]) => add(3, at[k] === v))
        if (fp.text) {
            const t = textOf(el)
            const l = Math.min(30, fp.text.length)
            add(6, t === fp.text || (t.length > 0 && (t.startsWith(fp.text.slice(0, l)) || fp.text.startsWith(t.slice(0, l)))))
        }
        const anc = ancestorsOf(el)
        ;(fp.anc || []).slice(0, 3).forEach((a, i) => add(1.5, !!anc[i] && anc[i].tag === a.tag && (!a.id || anc[i].id === a.id) && (!a.cls.length || a.cls.some((c) => anc[i].cls.includes(c)))))
        if (fp.prev) add(1.5, textOf(el.previousElementSibling) === fp.prev)
        if (fp.next) add(1.5, textOf(el.nextElementSibling) === fp.next)
        return max ? got / max : 0
    }
    const search = (fp) => {
        let pool
        if (fp.id && document.getElementById(fp.id)) pool = [document.getElementById(fp.id)]
        else {
            const first = fp.cls[0] ? '.' + CSS.escape(fp.cls[0]) : ''
            try { pool = [...document.querySelectorAll(fp.tag + first)] } catch { pool = [] }
            if (!pool.length && first) pool = [...document.querySelectorAll(fp.tag)]
        }
        let best = null, bestS = 0, second = 0
        pool.filter((e) => !inRoot(e)).slice(0, 4000).forEach((e) => {
            const v = similarity(e, fp)
            if (v > bestS) { second = bestS; best = e; bestS = v } else if (v > second) second = v
        })
        return best && bestS >= 0.7 && bestS - second >= 0.08 ? best : null
    }
    const anchored = new Map() // id nota → { el, how } oppure { el: null, at } se non trovato (si riprova dopo 2 secondi)
    // restituisce l'elemento della nota: dal selettore (validato con l'impronta) o cercato per somiglianza
    const resolve = (n) => {
        const c = anchored.get(n.id)
        if (c && (c.el ? c.el.isConnected : Date.now() - c.at < 2000)) return c.el
        let el = null, how = 'selector'
        if (!n.anchor) el = safeQuery(n.selector)
        else {
            for (const q of [n.sel2, n.selector]) {
                const e = safeQuery(q)
                if (e && !inRoot(e) && similarity(e, n.anchor) >= 0.6) { el = e; break }
            }
            if (!el) { el = search(n.anchor); how = 'search' }
        }
        anchored.set(n.id, el ? { el, how } : { el: null, at: Date.now() })
        if (el && cfg.team) maintain(n, el, how)
        return el
    }
    // il team aggiorna l'ancoraggio salvato: note vecchie senza impronta (la prendono ora che l'elemento c'è) o elemento ritrovato in un altro punto
    const maintained = new Set()
    const maintain = (n, el, how) => {
        if (maintained.has(n.id) || (n.anchor && how === 'selector')) return
        maintained.add(n.id)
        api(`/notes/${n.id}/anchor`, { method: 'POST', body: { selector: cssPath(el), sel2: stableSelector(el), anchor: fingerprint(el) } })
            .then((u) => { Object.assign(n, { selector: u.selector, sel2: u.sel2, anchor: u.anchor }) }).catch(() => {})
    }
    const isLost = (n) => !!n.anchor && !resolve(n)

    /* ---------- posizione delle puntine ---------- */
    const pointFor = (n) => {
        const el = resolve(n)
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
            adoptOldName()
            if (cfg.ready) state.ready = await api('/ready?path=' + encodeURIComponent(path))
            state.loaded = true
        } catch (e) { toast(e.message) }
        render()
    }

    /* ---------- render ---------- */
    const render = () => {
        renderFab()
        renderPins()
        renderPanel()
    }
    // filtro «Solo il mio gruppo» (lo ricorda il browser): vale per l'elenco e per le puntine
    const onlyMine = () => stored('dcn_only_group') === '1' && !!meGroup()
    const shown = (n) => (state.resolved || n.status !== 'resolved') && (!onlyMine() || n.group === meGroup())
    const visibleNotes = () => state.notes.filter(shown)

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
                groupTint(h('button', {
                    class: `pin ${n.status}${state.current === n.id && state.view === 'note' ? ' active' : ''}`,
                    type: 'button', 'data-id': n.id, title: (groupOf(n.group) ? groupOf(n.group).name + ' · ' : '') + n.text.slice(0, 80), 'aria-label': 'Nota ' + n.n,
                    onclick: () => openNote(n.id),
                }, h('span', {}, n.n)), n.group)
            )
        )
        placePins()
    }

    const togglePanel = (open) => {
        const show = open ?? panel.hidden
        panel.hidden = !show
        if (show) { state.view = state.view === 'note' ? 'note' : state.view === 'who' ? 'who' : 'list'; load() }
    }

    const numBadge = (n) => groupTint(h('span', { class: `num ${n.status}` }, n.n), n.group)
    const statusBadge = (s) => h('span', { class: `st ${s}` }, STATUS[s])

    const renderPanel = () => {
        if (panel.hidden) return
        // prima di tutto si sceglie chi scrive (una volta sola: poi lo ricorda il browser)
        if (state.loaded && !identified()) state.view = 'who'
        const who = state.view === 'who'
        const head = h('div', { class: 'head' },
            state.view === 'note' || (who && identified()) ? h('button', { class: 'icon', type: 'button', 'aria-label': 'Indietro', onclick: () => { state.view = 'list'; state.whoStep = ''; renderPins(); renderPanel() } }, '←') : null,
            h('h2', {}, state.view === 'note' ? 'Nota' : state.view === 'contacts' ? 'Persone esterne' : who ? 'Chi sei?' : `Note · ${cfg.label}`),
            h('button', { class: 'icon', type: 'button', 'aria-label': 'Chiudi', onclick: () => { panel.hidden = true } }, '✕')
        )
        const body = h('div', { class: 'body' })
        if (!state.loaded) body.append(h('div', { class: 'empty' }, 'Caricamento…'))
        panel.replaceChildren(...[head, who ? null : whoBar(), state.view === 'note' || who ? null : toolbar(), body, byDunk()].filter(Boolean))
        if (!state.loaded) return
        who ? renderWho(body) : state.view === 'note' ? renderNote(body) : state.view === 'contacts' ? renderContacts(body) : renderList(body)
    }

    const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700.9 334.8" fill="currentColor" aria-hidden="true"><path d="M140.5,19.1C129,6.4,111.7,0,88.8,0H0v235l64.8-8.8V56.2h7.1c6.2,0,10.9.6,13.9,1.9,3,1.2,5.1,3.7,6.2,7.3,1.1,3.6,1.7,9.1,1.7,16.3v140.6l65.1-8.8-.7-137.8c-.2-25-6.1-43.8-17.6-56.6h0Z"></path> <path d="M102.4,257.6c0,11.5-5.9,17.2-17.6,17.2h-11.2v-38.6l-64.8,8.8v86.8h86.5c26,0,44.6-8,55.8-24s16.7-39.8,16.5-71.5v-12.8c0,0-65.2,8.8-65.2,8.8v25.3h0Z"></path> <polygon points="236 0 173.4 0 173.4 211.5 236 203 236 0"></polygon> <polygon points="267.4 0 267.4 198.8 330 190.3 330 0 267.4 0"></polygon> <path d="M276.2,243c0,10.5-1,18.5-3,24.2s-6.2,8.4-12.7,8.4-10.7-2.9-12.7-8.6c-2-5.7-3-13.7-3-24v-30l-62.6,8.5v24.1c0,29.5,6.4,51.7,19.3,66.7,12.9,15,32.5,22.5,59,22.5s46.1-7.5,59-22.5,19.3-37.2,19.3-66.7v-45.3l-62.5,8.5v34.2h0Z"></path> <polygon points="451.1 331.8 514 331.8 514 176.5 422.5 188.9 451.1 331.8"></polygon> <polygon points="356 331.8 418.9 331.8 418.9 189.4 356 197.9 356 331.8"></polygon> <polygon points="443.1 0 443.1 153.9 413.5 0 347.2 0 347.2 187.9 410.1 179.4 410.1 160.7 413.8 178.9 505.3 166.5 505.3 0 443.1 0"></polygon> <polygon points="657.5 157.1 535 173.7 535 331.8 599 331.8 599 175.9 634.3 331.8 700.9 331.8 657.5 157.2 657.5 157.1"></polygon> <polygon points="684.7 0 619.9 0 589.9 128.9 589.9 0 526.3 0 526.3 163.7 648.8 147.1 684.7 0"></polygon></svg>'
    // piede del pannello: logo DUNK a sinistra, nome del plugin e versione a destra
    const byDunk = () => h('div', { class: 'by-dunk' },
        h('a', { class: 'logo', href: 'https://thedunk.it', target: '_blank', rel: 'noopener', 'aria-label': 'DUNK' }, document.createRange().createContextualFragment(LOGO)),
        h('span', { class: 'ver' }, 'DUNK Client Notes', cfg.version ? h('b', {}, ' v' + cfg.version) : null))

    const USER_PLUS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>'

    /* persone esterne: si aggiungono qui (nome + email) e si richiamano per segnalare una nota o una risposta */
    const renderContacts = (body) => {
        const name = h('input', { type: 'text', placeholder: 'Nome e cognome', maxlength: 80, required: true, 'aria-label': 'Nome e cognome' })
        const email = h('input', { type: 'email', placeholder: 'Email', maxlength: 120, required: true, 'aria-label': 'Email' })
        const add = h('button', { class: 'btn primary add', type: 'button', title: 'Aggiungi', 'aria-label': 'Aggiungi', onclick: async () => {
            add.disabled = true
            try { state.contacts = (await api('/contacts', { method: 'POST', body: { name: name.value, email: email.value } })).contacts; toast(cfg.team ? 'Persona aggiunta.' : 'Persona aggiunta: il team la confermerà.'); renderPanel() } catch (err) { toast(err.message); add.disabled = false }
        } }, '+')
        needs(add, name, email)
        body.append(
            h('p', { class: 'hint-txt' }, 'Aggiungi chi vuoi coinvolgere fuori dal team: scrivendo una nota o una risposta potrai segnalarla a queste persone, che la riceveranno via email.'),
            form({ class: 'form contact-form' }, name, h('div', { class: 'row' }, email, add))
        )
        const people = state.contacts.filter((c) => cfg.team || !c.pending)
        if (!people.length) { body.append(h('div', { class: 'empty' }, 'Nessuna persona aggiunta.')); return }
        people.forEach((c) => body.append(h('div', { class: 'contact' },
            h('span', { class: 'avatar' }, c.name.trim().charAt(0).toUpperCase()),
            h('span', { class: 'contact-info' }, h('b', {}, c.name, c.pending ? h('span', { class: 'pending' }, 'da confermare') : null), h('span', { class: 'meta' }, c.email))
        )))
    }

    /* ---------- chi scrive ----------
     * Si sceglie una volta sola, alla prima apertura del pannello: il proprio nome dall'elenco delle persone (rubrica),
     * poi il gruppo di lavoro. Il browser lo ricorda; nei moduli di nota e risposta non si riscrive più.
     * Chi non è in elenco si aggiunge da sé e resta «da confermare» finché il team non lo conferma in bacheca. */
    const me = () => { try { return JSON.parse(stored('dcn_me') || '{}') } catch { return {} } }
    const setMe = (v) => store('dcn_me', JSON.stringify({ ...me(), ...v }))
    const meContact = () => state.contacts.find((c) => c.id === me().contact) || null
    const meGroup = () => (groupOf(me().group) ? me().group : '')
    // il team scrive col suo utente WordPress: sceglie solo il gruppo (se ci sono gruppi)
    const identified = () => (cfg.team ? true : !!meContact() && (!GROUPS.length || !!meGroup()))
    const whoFields = () => ({ author: cfg.team ? cfg.user : meContact()?.name || '', contact: cfg.team ? '' : me().contact || '', group: meGroup() })
    // vecchie versioni: il nome scritto a mano, se coincide con una persona confermata, diventa la scelta
    const adoptOldName = () => {
        if (cfg.team || me().contact || !savedName()) return
        const c = state.contacts.find((x) => !x.pending && x.name.trim().toLowerCase() === savedName().trim().toLowerCase())
        if (c) setMe({ contact: c.id, group: groupOf(stored('dcn_group')) ? stored('dcn_group') : '' })
    }

    /* moduli: si inviano solo col pulsante, mai con Invio (nei campi di testo Invio non fa nulla, nelle aree di testo va a capo) */
    const form = (attrs, ...kids) => {
        const f = h('form', { ...attrs, novalidate: true, onsubmit: (e) => e.preventDefault() }, ...kids)
        f.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault() })
        return f
    }
    // pulsante spento finché i campi obbligatori sono vuoti
    const needs = (btn, ...fields) => {
        const check = () => { btn.disabled = fields.some((f) => !f.value.trim()) }
        fields.forEach((f) => f.addEventListener('input', check))
        check()
        return check
    }

    /* «Chi sei?»: elenco delle persone, «Non sono in elenco», poi il gruppo */
    const renderWho = (body) => {
        const mine = me().contact
        const people = state.contacts.filter((c) => !c.pending || c.id === mine).sort((a, b) => a.name.localeCompare(b.name, 'it'))
        const step = state.whoStep || (meContact() && GROUPS.length && !cfg.team ? 'group' : cfg.team ? 'group' : 'name')
        const done = () => {
            state.whoStep = ''
            state.view = 'list'
            const after = state.after
            state.after = null
            if (after === 'pick') startPick()
            else renderPanel()
        }
        if (step === 'group') {
            body.append(h('p', { class: 'hint-txt' }, cfg.team ? 'Con quale gruppo scrivi? Le tue note avranno il suo colore.' : 'Di che gruppo fai parte? Le tue note avranno il suo colore.'),
                h('div', { class: 'who-list' }, ...GROUPS.map((g) => h('button', { class: `who g${meGroup() === g.id ? ' on' : ''}`, type: 'button', style: `--g:${g.color};--g-ink:${g.ink}`, onclick: () => { setMe({ group: g.id }); done() } },
                    h('span', { class: 'dot' }), h('b', {}, g.name)))),
                cfg.team ? h('button', { class: 'btn', type: 'button', onclick: () => { setMe({ group: '' }); done() } }, 'Nessun gruppo') : h('button', { class: 'link-btn', type: 'button', onclick: () => { state.whoStep = 'name'; renderPanel() } }, '← Cambia nome'))
            return
        }
        if (step === 'new') {
            const first = h('input', { type: 'text', placeholder: 'Nome', maxlength: 40, 'aria-label': 'Nome', autocomplete: 'given-name' })
            const last = h('input', { type: 'text', placeholder: 'Cognome', maxlength: 40, 'aria-label': 'Cognome', autocomplete: 'family-name' })
            const email = h('input', { type: 'email', placeholder: 'Email', maxlength: 120, 'aria-label': 'Email', autocomplete: 'email' })
            const add = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                add.disabled = true
                try {
                    const res = await api('/contacts', { method: 'POST', body: { name: `${first.value.trim()} ${last.value.trim()}`, email: email.value } })
                    state.contacts = res.contacts
                    setMe({ contact: res.id })
                    state.whoStep = GROUPS.length ? 'group' : ''
                    if (state.whoStep) renderPanel(); else done()
                } catch (err) { toast(err.message); add.disabled = false }
            } }, 'Aggiungimi')
            needs(add, first, last, email)
            body.append(h('p', { class: 'hint-txt' }, 'Scrivi nome, cognome ed email. Puoi già scrivere note; il team confermerà il tuo nome.'),
                form({ class: 'form' }, h('div', { class: 'row' }, first, last), email, add,
                    h('button', { class: 'link-btn', type: 'button', onclick: () => { state.whoStep = 'name'; renderPanel() } }, '← Torna all’elenco')))
            first.focus()
            return
        }
        const filter = people.length > 8 ? h('input', { class: 'who-filter', type: 'search', placeholder: 'Cerca il tuo nome…', 'aria-label': 'Cerca il tuo nome' }) : null
        const list = h('div', { class: 'who-list' }, ...people.map((c) => h('button', { class: `who${c.id === mine ? ' on' : ''}`, type: 'button', 'data-name': c.name.toLowerCase(), onclick: () => {
            setMe({ contact: c.id })
            if (GROUPS.length) { state.whoStep = 'group'; renderPanel() } else done()
        } }, h('span', { class: 'avatar' }, c.name.trim().charAt(0).toUpperCase()), h('b', {}, c.name), c.pending ? h('span', { class: 'pending' }, 'da confermare') : null)))
        filter?.addEventListener('input', () => list.querySelectorAll('.who').forEach((b) => { b.hidden = !b.dataset.name.includes(filter.value.trim().toLowerCase()) }))
        body.append(...[h('p', { class: 'hint-txt' }, 'Scegli il tuo nome: lo useremo per le tue note e per avvisarti quando vengono chiuse.'),
            filter, people.length ? list : h('div', { class: 'empty' }, 'Nessun nome in elenco.'),
            h('button', { class: 'btn', type: 'button', onclick: () => { state.whoStep = 'new'; renderPanel() } }, 'Non sono in elenco')].filter(Boolean))
    }

    /* riga fissa in cima al pannello: con che nome e gruppo si sta scrivendo */
    const whoBar = () => {
        if (cfg.team && !GROUPS.length) return null
        const c = meContact()
        return h('div', { class: 'who-bar' }, h('span', {}, 'Scrivi come ', h('b', {}, cfg.team ? `${cfg.user} (team)` : c?.name || '—'), groupChip(meGroup()),
            !cfg.team && c?.pending ? h('span', { class: 'pending' }, 'da confermare') : null),
        h('button', { class: 'link-btn', type: 'button', onclick: () => { state.view = 'who'; state.whoStep = cfg.team ? 'group' : 'name'; renderPanel() } }, 'Cambia'))
    }

    /* scelta delle persone esterne da segnalare. Nei moduli di nota e risposta è chiusa finché non la si apre («+ Segnala a qualcuno») */
    const tagPicker = (label = 'Segnala a', preset = [], { pending = false, folded = false } = {}) => {
        const people = state.contacts.filter((c) => pending || !c.pending)
        const chosen = new Set(preset.filter((id) => people.some((c) => c.id === id)))
        if (!people.length) return { el: null, value: () => [] }
        const chips = h('div', { class: 'tag' }, h('span', { class: 'tag-label' }, label),
            ...people.map((c) => h('button', { class: 'chip', type: 'button', 'aria-pressed': String(chosen.has(c.id)), title: c.email + (c.pending ? ' · da confermare' : ''), onclick: (e) => {
                chosen.has(c.id) ? chosen.delete(c.id) : chosen.add(c.id)
                e.currentTarget.setAttribute('aria-pressed', String(chosen.has(c.id)))
            } }, c.name)))
        if (!folded) return { el: chips, value: () => [...chosen] }
        chips.hidden = true
        const open = h('button', { class: 'link-btn', type: 'button', onclick: () => { chips.hidden = false; open.remove() } }, '+ Segnala a qualcuno')
        return { el: h('div', {}, open, chips), value: () => [...chosen] }
    }

    const toolbar = () => {
        const src = (state.tab === 'all' ? (state.all || []) : state.notes).filter((n) => !onlyMine() || n.group === meGroup())
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
                // filtri come pulsantini uguali a sinistra, conteggio a destra: tutto su una riga
                h('div', { class: 'filters' },
                    h('button', { class: 'chip', type: 'button', 'aria-pressed': String(!!state.resolved), title: 'Mostra anche le note risolte', onclick: () => { state.resolved = !state.resolved; render() } }, 'Risolte'),
                    meGroup() ? h('button', { class: 'chip g', type: 'button', style: `--g:${groupOf(meGroup()).color};--g-ink:${groupOf(meGroup()).ink}`, 'aria-pressed': String(onlyMine()), title: 'Mostra solo le note del tuo gruppo, nell’elenco e sulla pagina',
                        onclick: () => { store('dcn_only_group', onlyMine() ? '' : '1'); render() } }, 'Solo ' + groupOf(meGroup()).name) : null),
                h('span', { class: 'count', title: `${open} apert${open === 1 ? 'a' : 'e'}, ${done} risolt${done === 1 ? 'a' : 'e'}` }, `${open} apert${open === 1 ? 'a' : 'e'}${done ? ` · ${done} risolt${done === 1 ? 'a' : 'e'}` : ''}`)
            ),
            readyBox()
        )
    }

    /* avviso «note pronte»: una sola email per pagina, non una per nota */
    const readyBox = () => {
        const open = state.notes.filter((n) => n.status === 'open').length
        if (!cfg.ready || state.tab !== 'page' || (!open && !state.ready?.sent)) return null
        const r = state.ready || { sent: false }
        const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`
        const fresh = r.sent ? r.fresh : open
        // se c'è qualcosa di nuovo da segnalare il pulsante è quello principale, altrimenti resta discreto
        const btn = h('button', { class: `btn${!r.sent || fresh ? ' primary' : ''}`, type: 'button', disabled: !open, onclick: () => sendReady(btn) }, r.sent && !fresh ? 'Invia di nuovo' : 'Segnala note pronte')
        const title = !r.sent ? 'Hai finito con questa pagina?' : fresh ? `${plural(fresh, 'nota nuova', 'note nuove')} da segnalare` : 'Avviso già inviato'
        const sub = !r.sent ? 'Avvisa il team che le note sono pronte da mettere in lavorazione.' : `Ultimo avviso: ${r.date} a ${r.to} (${plural(r.count, 'nota', 'note')}).`
        return h('div', { class: 'ready' }, h('div', { class: 'ready-txt' }, h('b', {}, title), h('span', {}, sub)), btn)
    }
    const sendReady = async (btn) => {
        const r = state.ready
        if (r?.sent && !r.fresh && !confirm('Non ci sono nuove note dall’ultimo avviso. Inviarlo di nuovo?')) return
        btn.disabled = true
        try {
            state.ready = await api('/ready', { method: 'POST', body: { path, ...whoFields() } })
            toast('Avviso inviato.')
        } catch (e) { toast(e.message) }
        renderPanel()
    }

    const renderList = (body) => {
        const src = state.tab === 'all' ? (state.all || []) : state.notes
        const list = src.filter(shown).slice().sort((a, b) => b.id - a.id)
        if (!list.length) {
            body.append(h('div', { class: 'empty' }, onlyMine() ? `Nessuna nota del gruppo ${groupOf(meGroup()).name}${state.tab === 'all' ? ' nel sito' : ' su questa pagina'}. Togli il filtro «Solo ${groupOf(meGroup()).name}» per vederle tutte.`
                : state.tab === 'all' ? 'Nessuna nota nel sito.' : 'Nessuna nota su questa pagina. Premi «Nuova nota», poi clicca sul punto da commentare.'))
            return
        }
        list.forEach((n) => body.append(h('button', { class: 'item', type: 'button', onclick: () => (n.path === path ? openNote(n.id) : (location.href = n.url ? `${n.url.split('#')[0]}#dcn-${n.id}` : `${n.path}#dcn-${n.id}`)) },
            numBadge(n),
            h('span', { class: 'item-body' },
                h('span', { class: 'txt' }, n.text),
                h('span', { class: 'item-meta' },
                    h('span', {}, `${n.author} · ${n.date}`, n.replies.length ? ` · ${n.replies.length} rispost${n.replies.length === 1 ? 'a' : 'e'}` : ''),
                    groupChip(n.group),
                    state.tab === 'all' ? h('code', { class: 'path' }, n.path) : null,
                    n.path === path && isLost(n) ? h('span', { class: 'lost', title: 'L’elemento della nota non è più in questa pagina: la puntina è solo approssimata' }, 'Elemento non trovato') : null,
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
        const el = resolve(n)
        if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' })
        renderPins()
        renderPanel()
    }

    /* correzione di una nota o di una risposta già inviata: solo finché la nota è aperta, per chi l'ha scritta (da questo browser) o per il team */
    const canEdit = (n, id) => n.status === 'open' && (cfg.team || !!keys()[id])
    const editable = (n, item) => {
        const txt = h('p', { class: 'txt' }, item.text)
        if (!canEdit(n, item.id)) return [txt, null]
        const btn = h('button', { class: 'edit', type: 'button', title: 'Correggi il testo', onclick: () => {
            const area = h('textarea', { maxlength: 2000, 'aria-label': 'Testo corretto' }, item.text)
            const save = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                save.disabled = true
                try { await api(`/items/${item.id}`, { method: 'POST', body: { text: area.value, key: keys()[item.id] || '' } }); await load(); toast('Testo corretto.') } catch (err) { toast(err.message); save.disabled = false }
            } }, 'Salva')
            needs(save, area)
            const f = form({ class: 'form edit-form' }, area, h('div', { class: 'row' }, save, h('button', { class: 'btn', type: 'button', onclick: () => { f.replaceWith(txt); btn.hidden = false } }, 'Annulla')))
            txt.replaceWith(f)
            btn.hidden = true
            area.focus()
        } }, 'Modifica')
        return [txt, btn]
    }

    /* cambio di stato (team): per «In lavorazione» e «Risolta» si scelgono le persone da avvisare via email e un messaggio facoltativo */
    const statusBox = (body, n) => {
        const row = h('div', { class: 'status-row' })
        const box = h('div')
        const setStatus = async (k, extra = {}) => {
            try {
                const res = await api(`/notes/${n.id}/status`, { method: 'POST', body: { status: k, ...extra } })
                await load()
                toast(res.notified?.length ? `Email inviata a ${res.notified.join(', ')}.` : `Stato: ${STATUS[k].toLowerCase()}.`)
            } catch (e) { toast(e.message) }
        }
        Object.entries(STATUS).forEach(([k, label]) => row.append(h('button', { class: `btn${n.status === k ? ' primary' : ''}`, type: 'button', onclick: () => {
            if (k === 'open') { setStatus(k); return }
            const who = tagPicker('Avvisa via email', n.involved || [], { pending: true })
            const msg = h('textarea', { placeholder: 'Messaggio (facoltativo)', maxlength: 2000, 'aria-label': 'Messaggio' })
            const ok = h('button', { class: 'btn primary', type: 'button', onclick: () => { ok.disabled = true; setStatus(k, { notify: who.value(), text: msg.value }) } }, k === 'resolved' ? 'Segna come risolta' : 'Metti in lavorazione')
            box.replaceChildren(form({ class: 'form status-form' },
                h('b', {}, k === 'resolved' ? 'Chiudi la nota' : 'Prendi in carico la nota'),
                who.el || h('p', { class: 'hint-txt' }, 'Per avvisare qualcuno via email aggiungilo tra le persone esterne (icona in alto nel pannello).'),
                msg, h('div', { class: 'row' }, ok, h('button', { class: 'btn', type: 'button', onclick: () => box.replaceChildren() }, 'Annulla'))))
        } }, label)))
        body.append(row, box, h('p', { class: 'link' }, h('a', { href: `${cfg.admin}&note=${n.id}`, target: '_blank', rel: 'noopener' }, 'Apri in bacheca')))
    }

    // intestazione uguale per nota e risposte: nome, gruppo, «da confermare» (solo per il team), data e «Modifica» a destra
    const whoLine = (item, edit, extra = null) => h('div', { class: 'who-line' },
        h('span', { class: 'who-name' }, h('b', {}, item.author), item.source === 'team' ? h('span', { class: 'meta' }, ' (team)') : null, groupChip(item.group),
            cfg.team && item.pending ? h('span', { class: 'pending', title: 'Si è aggiunto dal sito: confermalo in bacheca → Persone esterne' }, 'da confermare') : null),
        h('span', { class: 'who-date' }, item.date + (item.edited ? ' · modificata' : '')),
        extra, edit)

    const renderNote = (body) => {
        const n = state.notes.find((x) => x.id === state.current) || (state.all || []).find((x) => x.id === state.current)
        if (!n) { body.append(h('div', { class: 'empty' }, 'Nota non trovata.')); return }
        const [noteText, noteEdit] = editable(n, n)
        body.append(h('div', { class: 'note-card' },
            h('div', { class: 'note-head' }, numBadge(n), whoLine(n, noteEdit), statusBadge(n.status)),
            n.path === path && isLost(n) ? h('span', { class: 'lost', title: 'L’elemento della nota non è più in questa pagina: la puntina è solo approssimata' }, 'Elemento non trovato') : null,
            noteText,
            n.tagged?.length ? h('p', { class: 'tagged' }, 'Segnalata a: ' + n.tagged.join(', ')) : null,
            n.shot ? h('a', { class: 'shot', href: n.shot, target: '_blank', rel: 'noopener' }, h('img', { src: n.shot, alt: 'Screenshot della pagina al momento della nota', loading: 'lazy' })) : null
        ))
        if (cfg.team) statusBox(body, n)
        n.replies.forEach((r) => {
            const [txt, edit] = editable(n, r)
            body.append(h('div', { class: `reply${r.source === 'team' ? ' team' : ''}` }, whoLine(r, edit), txt,
                r.tagged?.length ? h('p', { class: 'tagged' }, (r.kind ? 'Avvisati via email: ' : 'Segnalata a: ') + r.tagged.join(', ')) : null))
        })
        // modulo di risposta: testo → «+ Segnala a qualcuno» (chiuso) → Rispondi
        const text = h('textarea', { placeholder: 'Scrivi una risposta…', maxlength: 2000, 'aria-label': 'Risposta' })
        const tags = tagPicker('Segnala a', [], { folded: true })
        const send = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            send.disabled = true
            try {
                const res = await api(`/notes/${n.id}/replies`, { method: 'POST', body: { text: text.value, tag: tags.value(), ...whoFields() } })
                saveKey(res.reply, res.key)
                await load()
                if (cfg.autoclose) { panel.hidden = true; state.view = 'list'; renderPins(); renderPanel() }
            } catch (err) { toast(err.message); send.disabled = false }
        } }, 'Rispondi')
        needs(send, text)
        body.append(form({ class: 'form reply-form' }, text, tags.el, send))
    }

    /* ---------- nuova nota: scelta dell'elemento ---------- */
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
            selector: cssPath(t), sel2: stableSelector(t), anchor: fingerprint(t),
            rx: r.width ? Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)) : 0.5,
            ry: r.height ? Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) : 0.5,
            px: (e.clientX + scrollX) / document.documentElement.scrollWidth,
            py: e.clientY + scrollY,
            cx: e.clientX, cy: e.clientY,
        }
        stopPick()
        pick.vh = innerHeight
        pick.shot = capture() // subito, con la pagina com'è nel momento del clic
        openComposer(pick)
    }
    const onKey = (e) => { if (e.key === 'Escape') stopPick() }
    const swallow = (e) => { if (target(e)) { e.preventDefault(); e.stopPropagation() } }

    const hint = h('div', { class: 'hint' }, 'Clicca sull’elemento da commentare ', h('button', { type: 'button', onclick: () => stopPick() }, 'Annulla'))
    function startPick() {
        if (!identified()) { state.after = 'pick'; state.view = 'who'; panel.hidden = false; load(); return }
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

    /* ---------- screenshot ----------
     * Si cattura la parte di pagina visibile nel momento del clic (non all'invio: intanto si può scorrere).
     * html2canvas disegna gli elementi `fixed` (es. header) dove starebbero a pagina non scorsa e ignora `sticky`:
     * nel clone li si mette in posizione assoluta/relativa nel punto in cui si vedono adesso. */
    const pinnedBoxes = () => {
        const out = []
        for (const el of document.body.querySelectorAll('*')) {
            if (inRoot(el)) continue
            const pos = getComputedStyle(el).position
            if (pos !== 'fixed' && pos !== 'sticky') continue
            const r = el.getBoundingClientRect()
            if (!r.width || !r.height) continue
            if (pos === 'fixed') {
                // contenitore della posizione assoluta: il primo antenato posizionato (o con transform), altrimenti la pagina
                let cb = el.parentElement
                while (cb && cb !== document.documentElement && getComputedStyle(cb).position === 'static' && getComputedStyle(cb).transform === 'none') cb = cb.parentElement
                const own = cb && cb !== document.documentElement
                const base = own ? cb.getBoundingClientRect() : { left: -scrollX, top: -scrollY }
                const bl = own ? cb.clientLeft - cb.scrollLeft : 0
                const bt = own ? cb.clientTop - cb.scrollTop : 0
                out.push([el, { position: 'absolute', left: (r.left - base.left - bl) + 'px', top: (r.top - base.top - bt) + 'px', right: 'auto', bottom: 'auto', width: r.width + 'px', height: r.height + 'px', margin: '0', transform: 'none' }])
            } else {
                // di quanto lo sticky è spostato rispetto alla sua posizione normale
                const prev = el.style.position
                el.style.position = 'static'
                const s = el.getBoundingClientRect()
                el.style.position = prev
                out.push([el, { position: 'relative', top: (r.top - s.top) + 'px', left: (r.left - s.left) + 'px', bottom: 'auto', right: 'auto' }])
            }
        }
        return out.slice(0, 200)
    }
    function capture() {
        if (typeof window.html2canvas !== 'function' || !innerWidth || !innerHeight) return Promise.resolve('')
        const scale = Math.min(1, 1280 / innerWidth)
        const boxes = pinnedBoxes()
        boxes.forEach(([el], i) => el.setAttribute('data-dcn-fx', i))
        const run = window.html2canvas(document.documentElement, {
            x: scrollX, y: scrollY, width: innerWidth, height: innerHeight, scale,
            useCORS: true, logging: false, backgroundColor: '#ffffff',
            windowWidth: document.documentElement.clientWidth, windowHeight: innerHeight,
            ignoreElements: (el) => el.id === 'dcn-root',
            onclone: (doc) => boxes.forEach(([, css], i) => { const c = doc.querySelector(`[data-dcn-fx="${i}"]`); if (c) Object.entries(css).forEach(([k, v]) => c.style.setProperty(k.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase()), v, 'important')) }),
        })
        // la puntina numerata la disegna il server (è lui ad assegnare il numero della nota)
        return Promise.race([run, new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 10000))])
            .then((canvas) => canvas.toDataURL('image/jpeg', 0.72)).catch(() => '')
            .finally(() => boxes.forEach(([el]) => el.removeAttribute('data-dcn-fx')))
    }

    /* ---------- composer ---------- */
    function openComposer(pick) {
        closeComposer()
        const text = h('textarea', { placeholder: 'Cosa vuoi segnalare o modificare?', maxlength: 2000, 'aria-label': 'Nota' })
        const shot = h('input', { type: 'checkbox', checked: true })
        const tags = tagPicker('Segnala a', [], { folded: true })
        const send = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            send.disabled = true; send.textContent = 'Invio…'
            try {
                const screenshot = shot.checked ? await pick.shot : ''
                const note = await api('/notes', { method: 'POST', body: {
                    path, url: location.href.split('#')[0], selector: pick.selector, sel2: pick.sel2, anchor: pick.anchor, rx: pick.rx, ry: pick.ry, px: pick.px, py: pick.py,
                    vw: innerWidth, text: text.value, screenshot, shot_fx: pick.cx / innerWidth, shot_fy: pick.cy / pick.vh, tag: tags.value(), ...whoFields(),
                } })
                saveKey(note.id, note.key)
                closeComposer()
                toast('Nota inviata. Grazie!')
                await load()
                openNote(note.id)
            } catch (err) { toast(err.message); send.disabled = false; send.textContent = 'Invia nota' }
        } }, 'Invia nota')
        needs(send, text)
        const who = meContact()
        const f = form({ class: 'composer' },
            h('h3', {}, 'Nuova nota'),
            cfg.team && !GROUPS.length ? null : h('p', { class: 'composer-who' }, 'Come ', h('b', {}, cfg.team ? cfg.user : who?.name || ''), groupChip(meGroup())),
            text, tags.el,
            h('label', { class: 'check' }, shot, 'Allega uno screenshot della pagina'),
            h('div', { class: 'row' }, send, h('button', { class: 'btn', type: 'button', onclick: closeComposer }, 'Annulla'))
        )
        const w = 320
        f.style.left = Math.max(12, Math.min(innerWidth - w - 12, pick.cx + 14)) + 'px'
        f.style.top = Math.max(12, Math.min(innerHeight - 330, pick.cy + 14)) + 'px'
        state.composer = { form: f, pick }
        overlay.append(f)
        text.focus()
        // html2canvas apre per un attimo un iframe e il cursore esce dal campo: a cattura finita lo si rimette
        pick.shot.then(() => { if (state.composer?.form === f && !f.contains(shadow.activeElement)) text.focus() })
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
