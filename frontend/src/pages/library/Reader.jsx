import { Pause, Play, RotateCcw, SkipBack, SkipForward, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import * as booksApi from '../../api/books.js';
import * as progressApi from '../../api/progress.js';
import * as ttsApi from '../../api/tts.js';
import * as translationsApi from '../../api/translations.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';
import { playPreparedAudio, prepareAudioElement } from '../../utils/audioPlayback.js';
import { ACCESS_LANGUAGES, speakText, speakTextInChunks } from '../../utils/multilingualSpeech.js';

const MAX_NARRATION_CHARS = 320;
const MAX_TRANSLATED_PAGE_CHARS = 100000;
const MAX_SERVER_TTS_CHARS = 950;
const NARRATION_TIMEOUT_MS = 15000;
const PAGE_TRANSLATION_TIMEOUT_MS = 180000;
const PLAY_START_TIMEOUT_MS = 5000;

function narrationExcerpt(text, maxLength = MAX_NARRATION_CHARS) {
  const clean = String(text || '').replace(/\s+/g, ' ').trim();
  if (clean.length <= maxLength) return clean;
  const excerpt = clean.slice(0, maxLength);
  const boundary = Math.max(excerpt.lastIndexOf('. '), excerpt.lastIndexOf('? '), excerpt.lastIndexOf('! '));
  return (boundary > 120 ? excerpt.slice(0, boundary + 1) : excerpt).trim();
}

function estimateLanguage(text) {
  const sample = ` ${String(text || '').toLowerCase().replace(/[^a-zàâçéèêëîïôùûüÿñæœ'\s-]/g, ' ')} `;
  const frenchHints = [' le ', ' la ', ' les ', ' des ', ' une ', ' du ', ' et ', ' est ', ' dans ', ' pour ', ' avec ', ' que ', ' qui ', ' je ', ' vous ', ' nous ', ' livre ', ' page '];
  const englishHints = [' the ', ' and ', ' is ', ' are ', ' in ', ' for ', ' with ', ' that ', ' this ', ' you ', ' we ', ' book ', ' page ', ' student ', ' science '];
  const accentScore = /[àâçéèêëîïôùûüÿñæœ]/.test(sample) ? 3 : 0;
  const frenchScore = frenchHints.reduce((score, word) => score + (sample.includes(word) ? 1 : 0), accentScore);
  const englishScore = englishHints.reduce((score, word) => score + (sample.includes(word) ? 1 : 0), 0);
  return frenchScore > englishScore + 1 ? 'fr' : 'en';
}

function ttsProviderFor(language) {
  return language === 'fr' ? 'mms' : 'speecht5';
}

function withTimeout(promise, timeoutMs, message) {
  let timer = 0;
  const timeout = new Promise((_, reject) => {
    timer = window.setTimeout(() => reject(new Error(message)), timeoutMs);
  });
  return Promise.race([promise, timeout]).finally(() => window.clearTimeout(timer));
}

export default function Reader() {
  const { id } = useParams();
  const [pageNumber, setPageNumber] = useState(1);
  const [speechState, setSpeechState] = useState('idle');
  const [speechError, setSpeechError] = useState('');
  const [rate, setRate] = useState(1);
  const [narrationLanguage, setNarrationLanguage] = useState(() => localStorage.getItem('mdl_reader_language') || 'en');
  const [translatedPageText, setTranslatedPageText] = useState('');
  const [translationStatus, setTranslationStatus] = useState('idle');
  const [navigationBusy, setNavigationBusy] = useState(false);
  const [preparedAudioUrl, setPreparedAudioUrl] = useState('');
  const [audioReady, setAudioReady] = useState(false);
  const initializedRef = useRef(false);
  const audioRef = useRef(null);
  const startedAtRef = useRef(0);
  const preparedAudioPromiseRef = useRef(null);
  const preparedAudioTextRef = useRef('');
  const browserSpeechRef = useRef(null);

  const bookState = useAsync(() => booksApi.getBook(id), [id]);
  const pageState = useAsync(() => booksApi.getBookPage(id, pageNumber), [id, pageNumber]);
  const progressState = useAsync(() => progressApi.getProgress(id), [id]);
  const book = bookState.data;
  const page = pageState.data;
  const totalPages = Math.max(1, Number(page?.total_pages || pageNumber || 1));
  const audioFiles = (book?.files || []).filter((file) => file.file_type === 'audio');
  const readableFile = (book?.files || []).find((file) => ['pdf', 'document', 'docx', 'txt'].includes(file.file_type));
  const currentText = String(page?.text || '').trim();
  const fallbackText = !currentText && book
    ? [book.title, book.description].filter(Boolean).join('. ')
    : '';
  const narratableText = currentText || fallbackText;
  const sourceLanguage = estimateLanguage(narratableText);
  const pageTranslationText = currentText.slice(0, MAX_TRANSLATED_PAGE_CHARS);
  const narrationText = narrationExcerpt(narratableText);
  const displayedPageText = translatedPageText || narratableText;
  const fullNarrationText = String(displayedPageText || '').trim();
  const serverNarrationText = fullNarrationText.length <= MAX_SERVER_TTS_CHARS ? fullNarrationText : '';
  const narrationAudioKey = `${narrationLanguage}:${serverNarrationText}`;
  const narrationLanguageLabel = ACCESS_LANGUAGES.find((item) => item.code === narrationLanguage)?.label || narrationLanguage;
  const translationRequested = narrationLanguage !== sourceLanguage;
  const progress = Math.min(100, Math.round((pageNumber / totalPages) * 100));
  const viewerUrl = readableFile?.file_type === 'pdf' ? booksApi.bookPageImageUrl(id, pageNumber) : '';

  useEffect(() => {
    if (initializedRef.current || progressState.loading) return;
    const savedPage = Number(progressState.data?.last_page || 0) + 1;
    if (savedPage > 1) setPageNumber(savedPage);
    initializedRef.current = true;
  }, [progressState.loading, progressState.data]);

  useEffect(() => () => audioRef.current?.pause(), []);

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
        if (active) setSpeechError(error.message);
      });
    return () => {
      active = false;
    };
  }, [preparedAudioUrl, rate]);

  useEffect(() => {
    let active = true;
    setTranslatedPageText('');
    setTranslationStatus('idle');
    if (pageState.loading || !pageTranslationText) {
      return () => { active = false; };
    }
    if (narrationLanguage === sourceLanguage) {
      setTranslatedPageText('');
      return () => { active = false; };
    }

    setTranslationStatus('loading');
    withTimeout(
      translationsApi.translateText({
        text: pageTranslationText,
        source_language: sourceLanguage,
        target_language: narrationLanguage,
        domain: 'book_page',
        quality_mode: 'fast',
      }),
      PAGE_TRANSLATION_TIMEOUT_MS,
      'Full page translation is still preparing. Try this page again in a moment.'
    )
      .then((response) => {
        const translated = String(response.data?.translated_text || '').trim();
        if (!active) return;
        if (translated) {
          setTranslatedPageText(translated);
          setTranslationStatus('translated');
        } else {
          setTranslationStatus('fallback');
        }
      })
      .catch((error) => {
        if (!active) return;
        setTranslationStatus('fallback');
        if (speechState !== 'idle') setSpeechError(error.message);
      });

    return () => {
      active = false;
    };
  }, [narrationLanguage, pageTranslationText, pageState.loading, sourceLanguage]);

  useEffect(() => {
    if (pageState.loading || !serverNarrationText || translationStatus === 'loading') {
      setPreparedAudioUrl('');
      setAudioReady(false);
      preparedAudioPromiseRef.current = null;
      preparedAudioTextRef.current = '';
      return undefined;
    }
    let active = true;
    setPreparedAudioUrl('');
    setAudioReady(false);
    preparedAudioTextRef.current = narrationAudioKey;
    preparedAudioPromiseRef.current = withTimeout(
      ttsApi.synthesize({
        book_id: Number(id),
        page_number: pageNumber,
        text: serverNarrationText,
        language: narrationLanguage,
        provider: ttsProviderFor(narrationLanguage),
      }),
      NARRATION_TIMEOUT_MS,
      'Narration preparation is taking too long. Try a shorter page section.'
    )
      .then((response) => {
        const audioUrl = ttsApi.audioUrl(response.data.audio_url);
        if (active && preparedAudioTextRef.current === narrationAudioKey) {
          setPreparedAudioUrl(audioUrl);
        }
        return audioUrl;
      })
      .catch((error) => {
        if (active && speechState !== 'idle') setSpeechError(error.message);
        throw error;
      });
    preparedAudioPromiseRef.current.catch(() => {});
    return () => {
      active = false;
    };
  }, [id, pageNumber, serverNarrationText, narrationAudioKey, pageState.loading, narrationLanguage, translationStatus]);

  async function saveProgress(nextPage = pageNumber, readingSeconds = 0) {
    const percentage = Math.min(100, Math.round((nextPage / totalPages) * 100));
    await progressApi.updateProgress(id, {
      last_page: Math.max(0, nextPage - 1),
      last_section: `Page ${nextPage} of ${totalPages}`,
      progress_percentage: percentage,
      total_reading_time_seconds: readingSeconds,
      completed_status: percentage >= 100 ? 'completed' : 'in_progress',
    });
  }

  function stopSpeech() {
    browserSpeechRef.current?.cancel?.();
    window.speechSynthesis?.cancel();
    browserSpeechRef.current = null;
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current.currentTime = 0;
    }
    setSpeechState('idle');
  }

  async function speak() {
    setSpeechError('');
    if (!narratableText) {
      setSpeechError('This page does not contain readable English text yet.');
      return;
    }
    if (audioRef.current && !audioRef.current.paused && !audioRef.current.ended) {
      setSpeechState('playing');
      return;
    }
    if (speechState === 'paused') {
      if (browserSpeechRef.current && window.speechSynthesis?.paused) {
        window.speechSynthesis.resume();
        setSpeechState('playing');
        return;
      }
      try {
        await playPreparedAudio(audioRef.current, preparedAudioUrl, rate);
        setSpeechState('playing');
      } catch (error) {
        setSpeechError(error.message);
        setSpeechState('idle');
      }
      return;
    }
    try {
      setSpeechState('loading');
      let audioText = fullNarrationText;
      if (narrationLanguage !== sourceLanguage && !translatedPageText) {
        const response = await withTimeout(
          translationsApi.translateText({ text: narrationText, source_language: sourceLanguage, target_language: narrationLanguage, domain: 'book_narration' }),
          NARRATION_TIMEOUT_MS,
          'Translation is taking too long. Try a shorter page section.'
        );
        audioText = narrationExcerpt(String(response.data?.translated_text || narrationText).trim());
      }
      if (audioText.length > MAX_SERVER_TTS_CHARS) {
        const reader = speakTextInChunks(audioText, narrationLanguage, { rate, chunkSize: 700 });
        browserSpeechRef.current = reader;
        reader.onend = () => setSpeechState('idle');
        reader.onerror = () => setSpeechState('idle');
        startedAtRef.current = Date.now();
        setSpeechState('playing');
        return;
      }
      let audioUrl = preparedAudioUrl;
      if (!audioUrl) {
        const audioKey = `${narrationLanguage}:${audioText}`;
        if (preparedAudioPromiseRef.current && preparedAudioTextRef.current === audioKey) {
          try {
            audioUrl = await withTimeout(
              preparedAudioPromiseRef.current,
              PLAY_START_TIMEOUT_MS,
              'Server narration is still preparing. Starting quick browser narration now.'
            );
          } catch {
            const utterance = speakText(audioText, narrationLanguage, { rate });
            browserSpeechRef.current = utterance;
            utterance.onend = () => setSpeechState('idle');
            utterance.onerror = () => setSpeechState('idle');
            startedAtRef.current = Date.now();
            setSpeechState('playing');
            return;
          }
        } else {
          const response = await withTimeout(
            ttsApi.synthesize({
              book_id: Number(id),
              page_number: pageNumber,
              text: audioText,
              language: narrationLanguage,
              provider: ttsProviderFor(narrationLanguage),
            }),
            PLAY_START_TIMEOUT_MS,
            'Server narration is still preparing. Starting quick browser narration now.'
          );
          audioUrl = ttsApi.audioUrl(response.data.audio_url);
        }
        setPreparedAudioUrl(audioUrl);
      }
      if (!audioReady) {
        await prepareAudioElement(audioRef.current, audioUrl, rate);
        setAudioReady(true);
      }
      startedAtRef.current = Date.now();
      await playPreparedAudio(audioRef.current, audioUrl, rate);
      setSpeechState('playing');
    } catch (error) {
      if (fullNarrationText) {
        try {
          const utterance = fullNarrationText.length > MAX_SERVER_TTS_CHARS
            ? speakTextInChunks(fullNarrationText, narrationLanguage, { rate, chunkSize: 700 })
            : speakText(fullNarrationText, narrationLanguage, { rate });
          browserSpeechRef.current = utterance;
          utterance.onend = () => setSpeechState('idle');
          utterance.onerror = () => setSpeechState('idle');
          startedAtRef.current = Date.now();
          setSpeechState('playing');
          return;
        } catch {
        }
      }
      setSpeechError(error.name === 'NotAllowedError'
        ? 'Your browser blocked audio playback. Click Play again after the narration button is ready.'
        : error.message);
      setSpeechState('idle');
    }
  }

  function primeSpeechPlayback() {
    const audio = audioRef.current;
    if (!audio || !audioReady || !preparedAudioUrl || speechState !== 'idle') return;
    audio.muted = false;
    audio.volume = 1;
    audio.playbackRate = rate;
    if (audio.ended || audio.currentTime >= Math.max(0, audio.duration - 0.15)) {
      audio.currentTime = 0;
    }
    startedAtRef.current = Date.now();
    const attempt = audio.play();
    if (attempt?.then) {
      attempt
        .then(() => {
          setSpeechError('');
          setSpeechState('playing');
        })
        .catch(() => {});
    }
  }

  function pause() {
    audioRef.current?.pause();
    window.speechSynthesis?.pause();
    setSpeechState('paused');
  }

  async function moveToPage(nextPage) {
    stopSpeech();
    const bounded = Math.min(Math.max(nextPage, 1), totalPages);
    setAudioReady(false);
    setPageNumber(bounded);
    await saveProgress(bounded);
  }

  async function moveToAdjacentPage(direction) {
    if (navigationBusy) return;
    stopSpeech();
    setNavigationBusy(true);
    try {
      let candidate = pageNumber + direction;
      let selected = Math.min(Math.max(candidate, 1), totalPages);
      for (let checked = 0; checked < 12 && candidate >= 1 && candidate <= totalPages; checked += 1) {
        const response = await booksApi.getBookPage(id, candidate);
        selected = candidate;
        if (!response.data?.is_blank) break;
        candidate += direction;
      }
      setAudioReady(false);
      setPageNumber(selected);
      await saveProgress(selected);
    } catch (error) {
      setSpeechError(error.message);
    } finally {
      setNavigationBusy(false);
    }
  }

  async function resetProgress() {
    stopSpeech();
    setAudioReady(false);
    setPageNumber(1);
    await progressApi.updateProgress(id, {
      last_page: 0,
      last_section: 'Page 1',
      progress_percentage: 0,
      total_reading_time_seconds: 0,
      completed_status: 'not_started',
    });
  }

  return (
    <DataState loading={bookState.loading} error={bookState.error} empty={!book} onRetry={bookState.reload}>
      <PageHeader title="Online book reader" description={book?.title} />
      <Card className="reader-card">
        <article className="reader-shell">
          <section className="reader-book-pane">
            <div className="reader-book-toolbar">
              <strong>Original and translated reader</strong>
              <div className="reader-book-navigation">
                <button type="button" aria-label="Previous page" disabled={pageNumber === 1 || navigationBusy} onClick={() => moveToAdjacentPage(-1)}><SkipBack size={17} /></button>
                <span>Page {pageNumber} of {totalPages}</span>
                <button type="button" aria-label="Next page" disabled={pageNumber >= totalPages || navigationBusy} onClick={() => moveToAdjacentPage(1)}><SkipForward size={17} /></button>
              </div>
            </div>
            {translationRequested ? (
              <div className="reader-document-compare">
                <div className="reader-document-column">
                  <div className="reader-document-label">Original document page</div>
                  {viewerUrl ? (
                    <div className="reader-page-image-wrap reader-page-image-wrap-compare">
                      <img className="reader-page-image" src={viewerUrl} alt={`${book?.title} original page ${pageNumber}`} />
                    </div>
                  ) : (
                    <div className="reader-translated-page reader-translated-page-loading">
                      <h2>No viewable original page</h2>
                      <p>This book has no PDF page image available.</p>
                    </div>
                  )}
                </div>
                <div className="reader-document-column">
                  <div className="reader-document-label">Full page translation</div>
                  {!currentText && !pageState.loading ? (
                    <div className="reader-translated-page reader-translated-page-loading">
                      <h2>No extractable text on this page</h2>
                      <p>This page appears to be a cover, image, or blank page. Open the next page to translate and read the book text.</p>
                    </div>
                  ) : translatedPageText ? (
                    <div className="reader-translated-page">
                      <div className="reader-translated-page-meta">
                        <span>AI translated page</span>
                        <strong>{narrationLanguageLabel}</strong>
                      </div>
                      <h1>{book?.title}</h1>
                      <h2>Page {pageNumber}</h2>
                      <p>{translatedPageText}</p>
                    </div>
                  ) : translationStatus === 'loading' ? (
                    <div className="reader-translated-page reader-translated-page-loading">
                      <div className="loader" />
                      <h2>Preparing full translated page...</h2>
                      <p>The translated document will appear here beside the original page as soon as the AI finishes.</p>
                    </div>
                  ) : (
                    <div className="reader-translated-page reader-translated-page-loading">
                      <h2>Full translation is still preparing</h2>
                      <p>This long page needs more time on the local AI model. Keep this page open or try again in a moment.</p>
                    </div>
                  )}
                </div>
              </div>
            ) : viewerUrl ? (
              <div className="reader-page-image-wrap">
                <img className="reader-page-image" src={viewerUrl} alt={`${book?.title} page ${pageNumber}`} />
              </div>
            ) : (
              <div className="state-panel">
                <h3>No viewable document</h3>
                <p>This book has no PDF, Word, or text file attached yet.</p>
              </div>
            )}
          </section>

          <section className="reader-listen-pane">
            {speechError && <div className="inline-error" role="alert">{speechError}</div>}
            <div className="reader-status">
              <strong>Page {pageNumber} narration</strong>
              <span>{progress}% completed</span>
            </div>
            <div className="progress-track"><span style={{ width: `${progress}%` }} /></div>
            <div className="reader-text">
              <h2>{pageState.loading ? 'Preparing page text...' : `Page ${pageNumber}`}</h2>
              {translationStatus === 'loading' && <small>Translating this page into {narrationLanguageLabel}...</small>}
              {translationStatus === 'fallback' && <small>The full {narrationLanguageLabel} translation is still preparing. The original text remains visible until it finishes.</small>}
              <p>{displayedPageText || 'This page is viewable, but no readable text was found for narration.'}</p>
            </div>
            <div className="reader-options">
              <label>
                Narration language
                <select value={narrationLanguage} onChange={(event) => { setNarrationLanguage(event.target.value); localStorage.setItem('mdl_reader_language', event.target.value); }} disabled={speechState !== 'idle'}>
                  {ACCESS_LANGUAGES.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
                </select>
              </label>
              <label>
                Narration speed
                <select value={rate} onChange={(event) => setRate(Number(event.target.value))} disabled={speechState !== 'idle'}>
                  <option value={0.75}>0.75x</option>
                  <option value={1}>1x</option>
                  <option value={1.25}>1.25x</option>
                  <option value={1.5}>1.5x</option>
                </select>
              </label>
            </div>
            <audio
              ref={audioRef}
              preload="auto"
              playsInline
              src={preparedAudioUrl || undefined}
              onEnded={() => {
                const seconds = Math.max(1, Math.round((Date.now() - startedAtRef.current) / 1000));
                setSpeechState('idle');
                saveProgress(pageNumber, seconds).catch(() => {});
              }}
              onLoadedData={() => setAudioReady(Boolean(preparedAudioUrl))}
              onCanPlayThrough={() => setAudioReady(Boolean(preparedAudioUrl))}
              onPlaying={() => setSpeechState('playing')}
              onError={() => {
                setSpeechState('idle');
                if (preparedAudioUrl) setSpeechError('Narration audio could not be loaded for playback.');
              }}
            />
            <div className="button-row centered">
              <Button variant="ghost" size="icon" aria-label="Previous narrated page" disabled={pageNumber === 1 || navigationBusy} onClick={() => moveToAdjacentPage(-1)}><SkipBack size={18} /></Button>
              <Button size="icon" aria-label={speechState === 'paused' ? 'Resume narration' : 'Play page narration'} onPointerDown={primeSpeechPlayback} onClick={speak} disabled={pageState.loading || !narrationText || ['playing', 'loading'].includes(speechState)}><Play size={18} /></Button>
              <Button variant="secondary" size="icon" aria-label="Pause narration" onClick={pause} disabled={speechState !== 'playing'}><Pause size={18} /></Button>
              <Button variant="ghost" size="icon" aria-label="Stop narration" onClick={stopSpeech} disabled={speechState === 'idle'}><Square size={18} /></Button>
              <Button variant="ghost" size="icon" aria-label="Next narrated page" disabled={pageNumber >= totalPages || navigationBusy} onClick={() => moveToAdjacentPage(1)}><SkipForward size={18} /></Button>
              <Button variant="ghost" size="icon" aria-label="Reset progress" onClick={resetProgress}><RotateCcw size={18} /></Button>
            </div>
            <form className="reader-page-jump" onSubmit={(event) => {
              event.preventDefault();
              const value = Number(new FormData(event.currentTarget).get('page'));
              moveToPage(value || 1);
            }}>
              <label>Go to page<input key={pageNumber} name="page" type="number" min="1" max={totalPages} defaultValue={pageNumber} /></label>
              <Button type="submit" variant="secondary">Open</Button>
            </form>
            {audioFiles.length > 0 && (
              <div className="book-audio-list">
                <h3>Uploaded audio</h3>
                {audioFiles.map((file) => (
                  <div className="book-audio" key={file.id}>
                    <span>{file.original_name}</span>
                    <audio controls preload="metadata" src={booksApi.fileStreamUrl(file.id)}>Your browser does not support audio playback.</audio>
                  </div>
                ))}
              </div>
            )}
          </section>
        </article>
      </Card>
    </DataState>
  );
}
