// Models — the game's 3D models, from assets/models.json (the 3D cast, made on the sprite page's 3D tab), loaded with three's
// GLTFLoader. The game keeps its own stand-in for each thing and asks for the model where it builds that thing:
//
//   Models.init(THREE, GLTFLoader)             once three is ready (index.html's module script, see ../AGENTS.md "3D models")
//   const m = Models.make('centipede-head');   a fresh Object3D, or null while it loads or when none is kept: keep the stand-in then
//   Models.on(key => …)                        called as each model finishes loading: swap that thing's stand-in for Models.make(key)
//   Models.has(key)  Models.size(key)  Models.clips(key)
//
// make() returns a group the game places like its stand-in: the model inside is scaled so its largest side is the cast's size (in
// world units), stands on y = 0, is centred on x and z, and faces +x (the artist makes every model facing +x). Skinned models are cloned with
// SkeletonUtils when it is given to init(THREE, GLTFLoader, SkeletonUtils). A model that fails to load is an error in the console
// naming its file, and stays absent (the stand-in shows); it is never replaced by anything else.
const Models = (() => {
  const cast = {}, loaded = {}, failed = {}, subs = []; let T = null, Skel = null, base = 'assets/';
  const fit = (key, scene) => { // the model normalised once: scaled to its size, on the ground, centred
    const box = new T.Box3().setFromObject(scene), dim = box.getSize(new T.Vector3()), big = Math.max(dim.x, dim.y, dim.z) || 1, s = (cast[key].size || 1) / big;
    const inner = new T.Group(); inner.add(scene); scene.scale.multiplyScalar(s);
    const c = box.getCenter(new T.Vector3()); scene.position.set(-c.x * s, -box.min.y * s, -c.z * s);
    return inner;
  };
  const load = (Loader, key) => {
    const it = cast[key]; new Loader().load(base + it.file + (it.v ? '?v=' + it.v : ''), g => { loaded[key] = { root: fit(key, g.scene), clips: g.animations || [], skinned: !!g.scene.getObjectByProperty('type', 'SkinnedMesh') }; for (const f of subs) try { f(key); } catch (e) { console.error(e); } },
      undefined, e => { failed[key] = String((e && e.message) || e); console.error(`Models: ${base}${it.file} (${key}) did not load: ${failed[key]}`); });
  };
  return {
    async init(THREE, GLTFLoader, SkeletonUtils, from) {
      T = THREE; Skel = SkeletonUtils || null; if (from) base = from;
      const r = await fetch(base + 'models.json', { cache: 'no-cache' }); if (r.status === 404) return; // no 3D cast yet
      if (!r.ok) { console.error(`Models: ${base}models.json answered ${r.status}`); return; }
      const j = await r.json(); for (const [k, it] of Object.entries(j.items || {})) { cast[k] = it; if (it.file) load(GLTFLoader, k); }
    },
    make(key) { const m = loaded[key]; if (!m) return null; const o = m.skinned && Skel ? Skel.clone(m.root) : m.root.clone(true); o.userData.model = key; return o; },
    has(key) { return !!loaded[key]; }, failed(key) { return failed[key] || ''; },
    size(key) { return cast[key] ? cast[key].size : 0; }, clips(key) { return loaded[key] ? loaded[key].clips : []; },
    on(fn) { subs.push(fn); for (const k of Object.keys(loaded)) try { fn(k); } catch (e) { console.error(e); } },
    keys() { return Object.keys(cast); },
  };
})();
