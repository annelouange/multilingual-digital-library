export function prepareAudioElement(audio, source, rate = 1) {
  if (!audio || !source) {
    return Promise.reject(new Error('Narration audio is not ready yet.'));
  }

  const sameSource = audio.currentSrc === source || audio.src === source;
  if (sameSource && audio.readyState >= 3) {
    audio.preload = 'auto';
    audio.playbackRate = rate;
    return Promise.resolve();
  }

  return new Promise((resolve, reject) => {
    let settled = false;
    let timer = 0;

    const cleanup = () => {
      window.clearTimeout(timer);
      audio.removeEventListener('canplaythrough', done);
      audio.removeEventListener('loadeddata', done);
      audio.removeEventListener('error', fail);
    };

    const finish = (callback) => {
      if (settled) return;
      settled = true;
      cleanup();
      callback();
    };

    function done() {
      finish(resolve);
    }

    function fail() {
      finish(() => reject(new Error('Narration audio could not be loaded for playback.')));
    }

    audio.addEventListener('canplaythrough', done);
    audio.addEventListener('loadeddata', done);
    audio.addEventListener('error', fail);
    timer = window.setTimeout(fail, 15000);

    if (!sameSource) {
      audio.src = source;
    }
    audio.preload = 'auto';
    audio.playbackRate = rate;
    if (!sameSource || audio.readyState < 2) {
      audio.load();
    }

    if (audio.readyState >= 3) {
      done();
    }
  });
}

export async function playPreparedAudio(audio, source, rate = 1) {
  const sameSource = audio && source && (audio.currentSrc === source || audio.src === source);
  if (!sameSource || audio.readyState < 3) {
    await prepareAudioElement(audio, source, rate);
  }
  audio.muted = false;
  audio.volume = 1;
  audio.playbackRate = rate;
  if (audio.ended || audio.currentTime >= Math.max(0, audio.duration - 0.15)) {
    audio.currentTime = 0;
  }
  await audio.play();
}
