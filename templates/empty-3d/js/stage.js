// The 3D stage (three.js, vendored in js/vendor): a renderer drawing into its own canvas, which Render copies onto the kit's
// canvas each frame, so the HUD and the screens draw on top as in any kit game. It starts empty: a sky, a floor and light.
// The builder adds the game's things here (with Models.make for the 3D cast, Poses for their moves: ../../kit/AGENTS.md).
const Stage = {
  ok: false,
  ready(fn) { if (window.THREE) fn(); else window.addEventListener('three-ready', fn, { once: true }); },
  /* the renderer, a camera looking down at the middle of the floor, a sky light and a sun. Without WebGL, ok stays false and Render draws a plain backdrop */
  init() {
    const T = THREE; this.T = T;
    try { this.canvas = document.createElement('canvas'); this.R = new T.WebGLRenderer({ canvas: this.canvas, antialias: true }); }
    catch (e) { console.error('Stage: no WebGL in this browser, so the 3D stage is off:', e); return false; }
    this.R.setPixelRatio(1);
    const sc = this.scene = new T.Scene(); sc.background = new T.Color(CFG.STAGE.sky); sc.fog = new T.Fog(CFG.STAGE.sky, CFG.STAGE.fogNear, CFG.STAGE.fogFar);
    this.cam = new T.PerspectiveCamera(CFG.STAGE.fov, 16 / 9, 1, 6000);
    sc.add(new T.HemisphereLight('#c9d6ff', '#3a3550', 1.6));
    const sun = new T.DirectionalLight('#ffffff', 1.8); sun.position.set(300, 600, 200); sc.add(sun);
    const floor = new T.Mesh(new T.PlaneGeometry(CFG.STAGE.floor, CFG.STAGE.floor), new T.MeshLambertMaterial({ color: CFG.STAGE.ground })); floor.rotation.x = -Math.PI / 2; sc.add(floor);
    const grid = new T.GridHelper(CFG.STAGE.floor, CFG.STAGE.floor / 50, '#6a6390', '#4a4568'); grid.position.y = 0.5; sc.add(grid);
    this.resize(Game.W, CFG.H); this.ok = true; return true;
  },
  resize(w, h) { if (!this.R) return; this.R.setSize(w, h, false); this.cam.aspect = w / h; this.cam.updateProjectionMatrix(); },
  /* draws the scene; the camera circles the middle slowly, so an empty stage still shows it is 3D */
  render(t) { if (!this.ok) return null; if (this.canvas.width !== Game.W || this.canvas.height !== CFG.H) this.resize(Game.W, CFG.H);
    const C = CFG.STAGE.cam, a = t * C.spin; this.cam.position.set(Math.cos(a) * C.dist, C.height, Math.sin(a) * C.dist); this.cam.lookAt(0, 0, 0);
    this.R.render(this.scene, this.cam); return this.canvas; },
};
