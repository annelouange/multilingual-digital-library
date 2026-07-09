import { Languages, Mic, Search, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as voiceApi from '../../api/voiceSearch.js';
import BookCard from '../../components/BookCard.jsx';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { ACCESS_LANGUAGES } from '../../utils/multilingualSpeech.js';

export default function VoiceSearch() {
  const [status, setStatus] = useState('idle');
  const [result, setResult] = useState(null);
  const [manualTranscript, setManualTranscript] = useState('');
  const [language, setLanguage] = useState(() => localStorage.getItem('mdl_voice_language') || 'en');
  const [error, setError] = useState('');
  const [processingSeconds, setProcessingSeconds] = useState(0);
  const recorderRef = useRef(null);
  const chunksRef = useRef([]);
  const timeoutRef = useRef(null);
  const { user } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (status !== 'processing') {
      setProcessingSeconds(0);
      return undefined;
    }
    const startedAt = Date.now();
    const timer = window.setInterval(() => setProcessingSeconds(Math.floor((Date.now() - startedAt) / 1000)), 500);
    return () => window.clearInterval(timer);
  }, [status]);

  async function submitPayload(payload) {
    payload.append('language', language);
    setStatus('processing');
    setError('');
    try {
      const response = await voiceApi.sendVoiceSearch(payload);
      setResult(response.data);
      setStatus('done');
    } catch (err) {
      setError(err.message);
      setStatus('idle');
    }
  }

  async function startRecording() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setError('Your browser does not support audio recording. Type a search phrase instead.');
      return;
    }
    setError('');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      chunksRef.current = [];
      const recorder = new MediaRecorder(stream);
      recorderRef.current = recorder;
      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunksRef.current.push(event.data);
      };
      recorder.onstop = async () => {
        clearTimeout(timeoutRef.current);
        stream.getTracks().forEach((track) => track.stop());
        const audio = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
        if (!audio.size) {
          setError('No audio was captured. Check the microphone and try again.');
          setStatus('idle');
          return;
        }
        const payload = new FormData();
        payload.append('audio', audio, 'voice-search.webm');
        await submitPayload(payload);
      };
      recorder.start();
      timeoutRef.current = setTimeout(() => recorder.state === 'recording' && recorder.stop(), 5000);
      setStatus('recording');
    } catch (err) {
      setError(err.name === 'NotAllowedError'
        ? 'Microphone permission was denied. Allow microphone access and try again.'
        : `Microphone could not start: ${err.message}`);
      setStatus('idle');
    }
  }

  function stopRecording() {
    recorderRef.current?.stop();
  }

  function searchTranscript(event) {
    event.preventDefault();
    const payload = new FormData();
    payload.append('transcript', manualTranscript);
    submitPayload(payload);
  }

  return (
    <>
      <PageHeader title="Voice book search" description="Search the full library catalog by speaking an English title, author, topic, faculty, department, or course." />
      <Card className="voice-card">
        <div className={`voice-pad voice-${status}`}>
          <div className="voice-orb"><Mic size={36} /></div>
          <h2>{status === 'recording' ? 'Listening...' : status === 'processing' ? `Processing audio... ${processingSeconds}s` : 'Ready to listen'}</h2>
          <p>Press start, speak a book title or subject clearly, then stop. The recording also stops automatically after 5 seconds.</p>
          <label className="compact-language-select"><Languages size={15} />
            <select value={language} onChange={(event) => { setLanguage(event.target.value); localStorage.setItem('mdl_voice_language', event.target.value); }} aria-label="Speech language">
              {ACCESS_LANGUAGES.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
            </select>
          </label>
          {error && <div className="inline-error">{error}</div>}
          <div className="button-row centered">
            <Button onClick={startRecording} disabled={status === 'recording' || status === 'processing'}><Mic size={16} /> Start recording</Button>
            <Button variant="secondary" onClick={stopRecording} disabled={status !== 'recording'}><Square size={16} /> Stop</Button>
          </div>
        </div>
      </Card>
      <Card title="Library text search">
        <form className="inline-form voice-manual-form" onSubmit={searchTranscript}>
          <input value={manualTranscript} onChange={(event) => setManualTranscript(event.target.value)} placeholder="Type any title, keyword, or topic to search the catalog..." />
          <Button type="submit" disabled={!manualTranscript.trim() || status === 'processing'}><Search size={16} /> Search</Button>
        </form>
      </Card>
      {result && (
        <Card title="Voice search result" eyebrow={`AI status: ${result.ai_status || 'ready'}`}>
          <div className="transcript-panel">
            <span>Transcript</span>
            <strong>{result.transcript || 'No transcript returned'}</strong>
            {result.search_transcript && result.search_transcript !== result.transcript && <small>Search text: {result.search_transcript}</small>}
            {result.language && <small>Language: {result.language}</small>}
            {result.stt?.confidence !== undefined && <small>Confidence: {(result.stt.confidence * 100).toFixed(1)}%</small>}
            {result.stt?.model && <small>Model: {result.stt.model}</small>}
            {result.stt?.provider && <small>Provider: {result.stt.provider.replaceAll('_', ' ')}</small>}
            {result.stt?.processing_seconds !== undefined && <small>Processing time: {Number(result.stt.processing_seconds).toFixed(2)} seconds</small>}
          </div>
          {result.action?.type === 'navigate' && (
            <div className="button-row">
              <Button onClick={() => navigate(result.action.path)}>{result.action.label}</Button>
            </div>
          )}
          {!result.action && (result.results?.length ? (
            <div className="book-grid">{result.results.map((book) => <BookCard key={book.id} book={book} role={user.role} />)}</div>
          ) : (
            <div className="state-panel"><h3>No matching books</h3><p>Try another phrase or make sure the STT service returned a usable transcript.</p></div>
          ))}
        </Card>
      )}
    </>
  );
}
