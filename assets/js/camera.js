/*
  In-page camera capture.
 *
  The driver photographs their licence and their own face with the live camera
  rather than picking a file. That is the point: picking a file lets someone hand
  over a photo of somebody else's licence, or of a face that is not theirs, and
  nothing on the server could tell. The camera cannot be fed a gallery image -
  whatever is in front of the lens is what gets stored.

  Both faces are captured the same way; only the lens differs. A licence is
  photographed with the rear camera, a face with the front one.

  Captures are posted as data URLs in a normal form field, so the form keeps
  ordinary submit, redirect and error behaviour and no upload plumbing is
  needed. The server decodes them and puts them through exactly the same
  extension and mime checks as an uploaded file.
*/
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-cam]').forEach((root) => {
    const facing = root.dataset.cam === 'user' ? 'user' : 'environment';
    const video = root.querySelector('[data-cam-video]');
    const hidden = root.querySelector('[data-cam-value]');
    const shutter = root.querySelector('[data-cam-shutter]');
    const retake = root.querySelector('[data-cam-retake]');
    const status = root.querySelector('[data-cam-status]');
    const shot = root.querySelector('[data-cam-shot]');
    const guide = root.querySelector('[data-cam-guide]');
    const form = root.closest('form');

    if (!video || !hidden || !shutter) return;

    // A face is only worth storing if it filled enough of the frame. Without this
    // the camera is a formality: hold it at the ceiling, tap, done.
    const MIN_FACE_RATIO = facing === 'user' ? 0.10 : 0.18;
    // Longest edge of the stored image. Big enough to read a licence number,
    // small enough that a data URL still fits comfortably in a POST.
    const MAX_EDGE = 1600;

    let stream = null;
    let taken = false;
    let starting = false;

    const say = (msg, tone) => {
      if (!status) return;
      status.textContent = msg || '';
      status.className = 'cam-status' + (tone ? ' is-' + tone : '');
      status.hidden = !msg;
    };

    const stop = () => {
      if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    };

    // Shown only when the camera could not start. Without it a driver who has
    // denied camera permission has no way forward: the shutter stays disabled
    // and the message tells them to try again at nothing.
    const showRetry = (yes) => {
      const b = root.querySelector('[data-cam-again]');
      if (b) b.hidden = !yes;
    };

    // Stop the camera whenever the driver navigates away, so the indicator light
    // on their phone is not left on after the page changes.
    window.addEventListener('pagehide', stop);
    window.addEventListener('beforeunload', stop);

    const start = async () => {
      if (starting || stream) return;
      starting = true;
      say('Starting the camera…');
      showRetry(false);
      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          throw new Error('nocam');
        }
        stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: { ideal: facing },
            width: { ideal: 1920 },
            height: { ideal: 1080 },
          },
          audio: false,
        });
        video.srcObject = stream;
        video.playsInline = true;
        video.muted = true;
        await video.play();

        root.classList.add('is-live');
        shutter.disabled = false;
        shutter.focus({ preventScroll: true });
        say(facing === 'user'
          ? 'Hold the phone at arm’s length, in the light.'
          : 'Lay the licence flat in good light and fill the frame.');
      } catch (err) {
        const name = err && err.name ? err.name : '';
        if (name === 'NotAllowedError' || name === 'SecurityError') {
          say('The camera is blocked. Allow camera access for this site in your browser, then tap Try again.', 'bad');
        } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
          say('No camera was found on this device.', 'bad');
        } else if (err && err.message === 'nocam') {
          say('This browser cannot open a camera. Try Chrome or Safari.', 'bad');
        } else {
          say('The camera would not start. Close anything else using it and try again.', 'bad');
        }
        root.classList.add('is-error');
        showRetry(true);
      } finally {
        starting = false;
      }
    };

    const draw = () => {
      const vw = video.videoWidth, vh = video.videoHeight;
      if (!vw || !vh) return null;

      // Cover the guide box, then centre-crop, so what the driver framed is
      // exactly what gets stored.
      let sw = vw, sh = vh, sx = 0, sy = 0;
      if (guide) {
        const gw = guide.offsetWidth, gh = guide.offsetHeight;
        if (gw && gh) {
          const target = gw / gh;
          const src = vw / vh;
          if (src > target) { sw = vh * target; sx = (vw - sw) / 2; }
          else { sh = vw / target; sy = (vh - sh) / 2; }
        }
      }
      const scale = Math.min(1, MAX_EDGE / Math.max(sw, sh));
      const cw = Math.round(sw * scale), ch = Math.round(sh * scale);

      const c = document.createElement('canvas');
      c.width = cw; c.height = ch;
      const ctx = c.getContext('2d');
      // A mirrored preview is friendlier for a selfie, but the stored image should
      // match what the camera saw, so the mirror is undone on the canvas.
      if (facing === 'user') { ctx.translate(cw, 0); ctx.scale(-1, 1); }
      ctx.drawImage(video, sx, sy, sw, sh, 0, 0, cw, ch);
      return { canvas: c, ctx, cw, ch };
    };

    // Rejects a shot that is too dark to be evidence of anything, or a selfie
    // taken from too far away to be the person.
    const quality = (ctx, cw, ch, data) => {
      const sample = document.createElement('canvas');
      const n = 48;
      sample.width = n; sample.height = n;
      sample.getContext('2d').drawImage(ctx.canvas, 0, 0, n, n);
      const d = sample.getContext('2d').getImageData(0, 0, n, n).data;
      let sum = 0, sum2 = 0, bright = 0;
      for (let i = 0; i < d.length; i += 4) {
        const v = (d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114);
        sum += v; sum2 += v * v; if (v > 26) bright++;
      }
      const px = d.length / 4;
      const mean = sum / px;
      const sd = Math.sqrt(Math.max(0, sum2 / px - mean * mean));
      if (mean < 34) return 'It is too dark to see. Move somewhere with more light.';
      if (sd < 11) return 'That came out blank. Hold steady and try again.';
      if (facing === 'user' && bright / px < MIN_FACE_RATIO) {
        return 'Fill the frame with your face — move closer to the phone.';
      }
      return null;
    };

    const capture = async () => {
      shutter.disabled = true;
      say('Hold still…');
      try {
        const d = draw();
        if (!d) throw new Error('noframe');
        await new Promise((r) => requestAnimationFrame(r));
        const bad = quality(d.ctx, d.cw, d.ch);
        if (bad) { say(bad, 'bad'); shutter.disabled = false; return; }

        const data = d.canvas.toDataURL('image/jpeg', 0.82);
        hidden.value = data;

        // The element ships with the `hidden` attribute, which sets display:none
        // and setting .src does not clear it - the photo was stored and posted
        // correctly while the driver was shown an empty frame.
        if (shot) { shot.src = data; shot.hidden = false; }
        showRetry(false);
        root.classList.add('is-taken');
        root.classList.remove('is-live');
        stop();
        shutter.hidden = true;
        if (retake) retake.hidden = false;
        say('Photo taken. If it is not clear, take it again.', 'ok');
        if (form) {
          form.querySelectorAll('[data-requires-capture]').forEach((b) => { b.disabled = false; });
        }
      } catch (err) {
        say('The camera did not give a usable frame. Try again.', 'bad');
        shutter.disabled = false;
      }
    };

    const reset = () => {
      hidden.value = '';
      showRetry(false);
      root.classList.remove('is-taken');
      root.classList.add('is-error');
      shutter.hidden = false;
      // Clear the preview too, or the previous shot is still on screen while the
      // driver frames the next one.
      if (shot) { shot.hidden = true; shot.removeAttribute('src'); }
      if (retake) retake.hidden = true;
      if (form) {
        form.querySelectorAll('[data-requires-capture]').forEach((b) => { b.disabled = true; });
      }
      start();
    };

    shutter.addEventListener('click', capture);
    if (retake) retake.addEventListener('click', reset);
    const again = root.querySelector('[data-cam-again]');
    if (again) again.addEventListener('click', reset);

    // The submit button stays disabled until there is a capture, so the form can
    // never post an empty photo. A capture handed back by the server after a
    // refused entry counts - the driver already took it.
    if (form) {
      form.querySelectorAll('[data-requires-capture]').forEach((b) => { b.disabled = hidden.value === ''; });
      form.addEventListener('submit', (e) => {
        if (hidden.value) return;
        e.preventDefault();
        say('Take a photo first.', 'bad');
      });
    }

    /*
      Do not open the lens until the block is actually on screen.

      A camera that starts the moment the page loads asks for permission before
      the driver has asked to take anything, and a page holding two of them -
      licence and picture - would light the indicator twice over on a page whose
      whole job was to show two documents that already exist. So a block sitting
      inside a closed <dialog> waits for that dialog; everything else starts at
      once, the way the setup wizard always has.
    */
    const hostDialog = root.closest('dialog');
    if (hostDialog) {
      if (hostDialog.open) start();
      else {
        // Watched on two signals on purpose. The `open` event is not dispatched
        // by showModal() in every engine we care about, and a camera that never
        // starts is a dead button; an attribute observer always fires when the
        // dialog is shown, and start() ignores a second call while it is running.
        const begin = () => { if (!taken && !stream) start(); };
        hostDialog.addEventListener('open', begin);
        new MutationObserver(begin).observe(hostDialog, { attributeFilter: ['open'] });
        // Closing without taking releases the lens, so the indicator light does
        // not stay on behind a dialog nobody can see any more.
        hostDialog.addEventListener('close', () => {
          if (taken) return;
          stop();
          shutter.disabled = true;
        });
      }
    } else {
      start();
    }
  });
});