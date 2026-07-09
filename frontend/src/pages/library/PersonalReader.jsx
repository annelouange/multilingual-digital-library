import { Pause, Play, SkipBack, SkipForward, Square } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import * as personalBooksApi from '../../api/personalBooks.js';
import * as ttsApi from '../../api/tts.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';
import { playPreparedAudio, prepareAudioElement } from '../../utils/audioPlayback.js';

const MAX_NARRATION_CHARS = 320;
const NARRATION_TIMEOUT_MS = 90000;

function sectionsFrom(text, maxLength = 2800) {
  const sentences = String(text || '').match(/[^.!?]+[.!?]+|[^.!?]+$/g) || [];
  const sections = [];
  let current = '';
  sentences.forEach((sentence) => {
    if (current && current.length + sentence.length > maxLength) {
      sections.push(current.trim());
      current = '';
    }
    current = `${current} ${sentence.trim()}`;
  });
  if (current.trim()) sections.push(current.trim());
  return sections;
}

function narrationExcerpt(text, maxLength = MAX_NARRATION_CHARS) {
  const clean = String(text || '').replace(/\s+/g, ' ').trim();
  if (clean.length <= maxLength) return clean;
  const excerpt = clean.slice(0, maxLength);
  const boundary = Math.max(excerpt.lastIndexOf('. '), excerpt.lastIndexOf('? '), excerpt.lastIndexOf('! '));
  return (boundary > 120 ? excerpt.slice(0, boundary + 1) : excerpt).trim();
}

function withTimeout(promise, timeoutMs, message) {
  let timer = 0;
  const timeout = new Promise((_, reject) => {
    timer = window.setTimeout(() => reject(new Error(message)), timeoutMs);
  });
  return Promise.race([promise, timeout]).finally(() => window.clearTimeout(timer));
}

export default function PersonalReader() {
  const { id } = useParams();
  const book = useAsync(() => personalBooksApi.getPersonalBook(id), [id]);
  const content = useAsync(() => personalBooksApi.getPersonalBookContent(id), [id]);
  const audioRef = useRef(null);
  const [sectionIndex, setSectionIndex] = useState(0);
  const [audioState, setAudioState] = useState('idle');
  const [audioError, setAudioError] = useState('');
  const [rate, setRate] = useState(1);
  const [preparedAudioUrl, setPreparedAudioUrl] = useState('');
  const [audioReady, setAudioReady] = useState(false);
  const sections = useMemo(() => sectionsFrom(content.data?.text), [content.data?.text]);
  const currentSection = sections[sectionIndex] || '';
  const narrationText = narrationExcerpt(currentSection);

  useEffect(() => () => audioRef.current?.pause(), []);

  useEffect(() => {
    if (!narrationText) {
      setPreparedAudioUrl('');
      setAudioReady(false);
      return undefined;
    }

    let active = true;
    setPreparedAudioUrl('');
    setAudioReady(false);
    withTimeout(
      ttsApi.synthesize({ text: narrationText, language: 'en', provider: 'clear' }),
      NARRATION_TIMEOUT_MS,
      'Narration preparation is taking too long. Try a shorter section.'
    )
      .then((response) => {
        if (active) setPreparedAudioUrl(ttsApi.audioUrl(response.data.audio_url));
      })
      .catch((error) => {
        if (active) setAudioError(error.message);
      });

    return () => {
      active = false;
    };
  }, [narrationText]);

  useEffect(() => {
    if (!preparedAudioUrl || !audioRef.current) {
      setAudioReady(false);
      return undefined;
    }
    let active = true;
    setAudioReady(false);
    prepareAudioElement(audioRef.current, preparedAudioUrl, rate)
      .then(() => {
        if (active) setAudioReady(true);
      })
      .catch((error) => {
        if (active) setAudioError(error.message);
      });
    return () => {
      active = false;
    };
  }, [preparedAudioUrl, rate]);

  function stop() {
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current.currentTime = 0;
    }
    setAudioState('idle');
  }

  async function play() {
    setAudioError('');
    if (audioRef.current && !audioRef.current.paused && !audioRef.current.ended) {
      setAudioState('playing');
      return;
    }
    if (audioState === 'paused' && audioRef.current) {
      try {
        await playPreparedAudio(audioRef.current, preparedAudioUrl, rate);
        setAudioState('playing');
      } catch (err) {
        setAudioError(err.message);
        setAudioState('idle');
      }
      return;
    }
    try {
      setAudioState('loading');
      await playPreparedAudio(audioRef.current, preparedAudioUrl, rate);
      setAudioState('playing');
    } catch (err) {
      setAudioError(err.name === 'NotAllowedError'
        ? 'Your browser blocked audio playback. Click Play again after the narration button is ready.'
        : err.message);
      setAudioState('idle');
    }
  }

  function primePlayback() {
    const audio = audioRef.current;
    if (!audio || !audioReady || !preparedAudioUrl || audioState !== 'idle') return;
    audio.muted = false;
    audio.volume = 1;
    audio.playbackRate = rate;
    if (audio.ended || audio.currentTime >= Math.max(0, audio.duration - 0.15)) {
      audio.currentTime = 0;
    }
    const attempt = audio.play();
    if (attempt?.then) {
      attempt
        .then(() => {
          setAudioError('');
          setAudioState('playing');
        })
        .catch(() => {});
    }
  }

  function pause() {
    audioRef.current?.pause();
    setAudioState('paused');
  }

  function move(next) {
    stop();
    setAudioReady(false);
    setSectionIndex(Math.min(Math.max(next, 0), Math.max(sections.length - 1, 0)));
  }

  return (
    <DataState loading={book.loading || content.loading} error={book.error || content.error} empty={!book.data} onRetry={() => { book.reload(); content.reload(); }}>
      <PageHeader title={book.data?.title || 'Private book'} description="Read privately or listen with server-generated English narration." />
      <Card>
        <article className="reader-panel">
          {audioError && <div className="inline-error" role="alert">{audioError}</div>}
          <div className="reader-status">
            <strong>{sections.length ? `Section ${sectionIndex + 1} of ${sections.length}` : 'No readable sections'}</strong>
            <span>{book.data?.author || 'Unknown author'}</span>
          </div>
          <div className="reader-text"><p>{currentSection || book.data?.description || 'No readable text was found.'}</p></div>
          <div className="reader-options">
            <label>Narration speed
              <select value={rate} onChange={(event) => setRate(Number(event.target.value))} disabled={audioState === 'playing'}>
                <option value={0.75}>0.75x</option><option value={1}>1x</option><option value={1.25}>1.25x</option><option value={1.5}>1.5x</option>
              </select>
            </label>
          </div>
          <audio
            ref={audioRef}
            preload="auto"
            playsInline
            src={preparedAudioUrl || undefined}
            onEnded={() => setAudioState('idle')}
            onLoadedData={() => setAudioReady(Boolean(preparedAudioUrl))}
            onCanPlayThrough={() => setAudioReady(Boolean(preparedAudioUrl))}
            onPlaying={() => setAudioState('playing')}
            onError={() => {
              setAudioState('idle');
              if (preparedAudioUrl) setAudioError('Narration audio could not be loaded for playback.');
            }}
          />
          <div className="button-row centered">
            <Button variant="ghost" size="icon" aria-label="Previous section" disabled={sectionIndex === 0} onClick={() => move(sectionIndex - 1)}><SkipBack size={18} /></Button>
            <Button size="icon" aria-label="Play narration" disabled={!narrationText || !audioReady || ['playing', 'loading'].includes(audioState)} onPointerDown={primePlayback} onClick={play}><Play size={18} /></Button>
            <Button variant="secondary" size="icon" aria-label="Pause narration" disabled={audioState !== 'playing'} onClick={pause}><Pause size={18} /></Button>
            <Button variant="ghost" size="icon" aria-label="Stop narration" disabled={audioState === 'idle'} onClick={stop}><Square size={18} /></Button>
            <Button variant="ghost" size="icon" aria-label="Next section" disabled={!sections.length || sectionIndex >= sections.length - 1} onClick={() => move(sectionIndex + 1)}><SkipForward size={18} /></Button>
          </div>
        </article>
      </Card>
    </DataState>
  );
}
