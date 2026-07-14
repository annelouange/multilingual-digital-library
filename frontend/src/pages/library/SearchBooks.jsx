import { Mic, Search, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import * as academicApi from '../../api/academic.js';
import * as bookmarksApi from '../../api/bookmarks.js';
import * as booksApi from '../../api/books.js';
import * as borrowApi from '../../api/borrow.js';
import * as favoritesApi from '../../api/favorites.js';
import * as searchApi from '../../api/search.js';
import * as voiceApi from '../../api/voiceSearch.js';
import BookCard from '../../components/BookCard.jsx';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Toast from '../../components/Toast.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { useAsync } from '../../hooks/useAsync.js';

const emptySearch = { q: '', faculty_id: '', department_id: '', category_id: '', language: '', access_level: '', has_audio: '', has_translation: '', has_summary: '', availability: '', sort: '' };

export default function SearchBooks() {
  const [urlParams] = useSearchParams();
  const urlQuery = urlParams.get('q') || '';
  const [params, setParams] = useState({ ...emptySearch, q: urlQuery });
  const [draft, setDraft] = useState({ ...emptySearch, q: urlQuery });
  const [toast, setToast] = useState('');
  const [voiceStatus, setVoiceStatus] = useState('idle');
  const recorderRef = useRef(null);
  const recognitionRef = useRef(null);
  const chunksRef = useRef([]);
  const timeoutRef = useRef(null);
  const { user } = useAuth();
  const results = useAsync(() => searchApi.searchBooks(params), [params]);
  const faculties = useAsync(() => academicApi.listAcademic('faculties'), []);
  const departments = useAsync(() => academicApi.listAcademic('departments'), []);
  const categories = useAsync(() => academicApi.listAcademic('categories'), []);
  const books = results.data?.results || [];
  const contentMatches = results.data?.contentMatches || [];
  const parsedQuery = results.data?.parsedQuery;
  const visibleDepartments = (departments.data || []).filter((item) => !draft.faculty_id || String(item.faculty_id) === String(draft.faculty_id));
  const selectedFaculty = (faculties.data || []).find((item) => String(item.id) === String(params.faculty_id));
  const selectedDepartment = (departments.data || []).find((item) => String(item.id) === String(params.department_id));
  const selectedCategory = (categories.data || []).find((item) => String(item.id) === String(params.category_id));

  useEffect(() => {
    setDraft((current) => ({ ...current, q: urlQuery }));
    setParams((current) => ({ ...current, q: urlQuery }));
  }, [urlQuery]);

  async function handleBorrow(bookId) {
    try {
      await borrowApi.requestBorrow(bookId);
      setToast('Borrow request sent.');
    } catch (error) {
      setToast(error.message);
    }
  }

  async function handleFavorite(bookId) {
    try {
      const response = await favoritesApi.toggleFavorite(bookId);
      setToast(response.data?.favorite ? 'Book added to favorites.' : 'Book removed from favorites.');
    } catch (error) {
      setToast(error.message);
    }
  }

  async function handleBookmark(bookId) {
    try {
      await bookmarksApi.addBookmark(bookId, { section: 'Book record', note: 'Saved from search results' });
      setToast('Bookmark saved.');
    } catch (error) {
      setToast(error.message);
    }
  }

  async function handleDelete(book) {
    if (!window.confirm(`Delete "${book.title}" from the public catalogue? Existing activity history will be preserved.`)) return;
    try {
      await booksApi.deleteBook(book.id);
      setToast(`"${book.title}" was removed from the catalogue.`);
      results.reload();
    } catch (err) {
      setToast(err.message);
    }
  }

  async function processRecording(audio) {
    setVoiceStatus('processing');
    const payload = new FormData();
    payload.append('audio', audio, 'catalog-search.webm');
    try {
      const response = await voiceApi.sendVoiceSearch(payload);
      const transcript = String(response.data?.transcript || '').trim();
      if (!transcript) throw new Error('The trained model did not return a transcript. Try speaking more clearly.');
      const next = { ...draft, q: transcript };
      setDraft(next);
      setParams(next);
      setToast(`Voice search: "${transcript}"`);
    } catch (error) {
      setToast(error.message);
    } finally {
      setVoiceStatus('idle');
    }
  }

  async function searchTranscript(transcript, source = 'Voice search') {
    const normalized = String(transcript || '').trim();
    if (!normalized) {
      setToast('No speech was recognized. Try again and speak clearly.');
      setVoiceStatus('idle');
      return;
    }
    setVoiceStatus('processing');
    const payload = new FormData();
    payload.append('transcript', normalized);
    try {
      const response = await voiceApi.sendVoiceSearch(payload);
      const recognized = String(response.data?.transcript || normalized).trim();
      setDraft((current) => {
        const next = { ...current, q: recognized };
        setParams(next);
        return next;
      });
      setToast(`${source}: "${recognized}"`);
    } catch (error) {
      setToast(error.message);
    } finally {
      setVoiceStatus('idle');
    }
  }

  function startBrowserSpeechRecognition() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return false;

    const recognition = new SpeechRecognition();
    recognitionRef.current = recognition;
    recognition.lang = 'en-US';
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.onstart = () => setVoiceStatus('recording');
    recognition.onresult = (event) => {
      const transcript = event.results?.[0]?.[0]?.transcript;
      searchTranscript(transcript, 'Speech search');
    };
    recognition.onerror = (event) => {
      setVoiceStatus('idle');
      setToast(event.error === 'not-allowed'
        ? 'Microphone permission was denied. Allow microphone access and try again.'
        : event.error === 'no-speech'
          ? 'No speech was detected. Press the microphone and speak a book title or topic.'
          : `Speech recognition failed: ${event.error}`);
    };
    recognition.onend = () => {
      recognitionRef.current = null;
      setVoiceStatus((current) => current === 'recording' ? 'idle' : current);
    };
    recognition.start();
    return true;
  }

  async function startRecording() {
    if (startBrowserSpeechRecognition()) return;

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setToast('This browser cannot record audio. Type your search instead.');
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      chunksRef.current = [];
      const recorder = new MediaRecorder(stream);
      recorderRef.current = recorder;
      recorder.ondataavailable = (event) => event.data.size > 0 && chunksRef.current.push(event.data);
      recorder.onstop = () => {
        clearTimeout(timeoutRef.current);
        stream.getTracks().forEach((track) => track.stop());
        const audio = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
        if (!audio.size) {
          setVoiceStatus('idle');
          setToast('No audio was captured. Check your microphone and try again.');
          return;
        }
        processRecording(audio);
      };
      recorder.start();
      setVoiceStatus('recording');
      timeoutRef.current = setTimeout(() => recorder.state === 'recording' && recorder.stop(), 5000);
    } catch (error) {
      setVoiceStatus('idle');
      setToast(error.name === 'NotAllowedError'
        ? 'Microphone permission was denied. Allow access and try again.'
        : `Microphone could not start: ${error.message}`);
    }
  }

  function stopRecording() {
    if (recognitionRef.current) {
      recognitionRef.current.stop();
      recognitionRef.current = null;
    }
    if (recorderRef.current?.state === 'recording') recorderRef.current.stop();
  }

  useEffect(() => () => {
    clearTimeout(timeoutRef.current);
    recognitionRef.current?.abort();
    const recorder = recorderRef.current;
    if (recorder) {
      recorder.ondataavailable = null;
      recorder.onstop = null;
      if (recorder.state === 'recording') recorder.stop();
      recorder.stream?.getTracks().forEach((track) => track.stop());
    }
  }, []);

  const recommendationLabel = selectedDepartment?.name || selectedFaculty?.name || selectedCategory?.name || 'All library books';

  return (
    <>
      <PageHeader title="Browse and search books" description="View the complete catalog, choose a faculty or department, or speak a book title, author, or topic into the microphone." />
      <Toast message={toast} onClose={() => setToast('')} />
      <Card>
        <form className="catalog-search-form" onSubmit={(event) => { event.preventDefault(); setParams(draft); }}>
          <div className={`field-with-icon catalog-query ${voiceStatus === 'recording' ? 'is-recording' : ''}`}>
            <Search size={18} />
            <input value={draft.q} onChange={(event) => setDraft({ ...draft, q: event.target.value })} placeholder="Search titles, authors, topics, faculties..." />
            <button
              type="button"
              className="microphone-search"
              aria-label={voiceStatus === 'recording' ? 'Stop microphone search' : 'Search using microphone'}
              onClick={voiceStatus === 'recording' ? stopRecording : startRecording}
              disabled={voiceStatus === 'processing'}
              title="Speak a book title, author, topic, faculty, or department"
            >
              {voiceStatus === 'recording' ? <Square size={17} /> : <Mic size={18} />}
            </button>
          </div>
          <select aria-label="Faculty" value={draft.faculty_id} onChange={(event) => setDraft({ ...draft, faculty_id: event.target.value, department_id: '' })}>
            <option value="">All faculties</option>
            {(faculties.data || []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <select aria-label="Department" value={draft.department_id} onChange={(event) => setDraft({ ...draft, department_id: event.target.value })}>
            <option value="">All departments</option>
            {visibleDepartments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <select aria-label="Collection" value={draft.category_id} onChange={(event) => setDraft({ ...draft, category_id: event.target.value })}>
            <option value="">All collections</option>
            {(categories.data || []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <select aria-label="Language" value={draft.language} onChange={(event) => setDraft({ ...draft, language: event.target.value })}>
            <option value="">Any language</option><option value="en">English</option><option value="fr">French</option>
          </select>
          <select aria-label="Access level" value={draft.access_level} onChange={(event) => setDraft({ ...draft, access_level: event.target.value })}>
            <option value="">Any access</option><option value="metadata_only">Metadata only</option><option value="read_online">Read online</option><option value="download">Download</option><option value="premium">Premium</option>
          </select>
          <select aria-label="AI features" value={draft.has_audio ? 'audio' : draft.has_translation ? 'translation' : draft.has_summary ? 'summary' : ''} onChange={(event) => { const value = event.target.value; setDraft({ ...draft, has_audio: value === 'audio' ? '1' : '', has_translation: value === 'translation' ? '1' : '', has_summary: value === 'summary' ? '1' : '' }); }}>
            <option value="">Any AI feature</option><option value="audio">Has audio</option><option value="translation">Has translation</option><option value="summary">Has summary</option>
          </select>
          <select aria-label="Availability" value={draft.availability} onChange={(event) => setDraft({ ...draft, availability: event.target.value })}>
            <option value="">Any availability</option><option value="available">Available now</option><option value="unavailable">Unavailable</option>
          </select>
          <select aria-label="Sort books" value={draft.sort} onChange={(event) => setDraft({ ...draft, sort: event.target.value })}>
            <option value="">Recommended</option><option value="title">Title</option><option value="year">Publication year</option>
          </select>
          <Button type="submit" disabled={voiceStatus === 'processing'}>{voiceStatus === 'processing' ? 'Processing voice...' : 'Show books'}</Button>
        </form>
        {params.q && parsedQuery && (
          <p className="query-interpretation">
            Interpreted as: <strong>{parsedQuery.keywords || params.q}</strong>
            {parsedQuery.author && <> - author <strong>{parsedQuery.author}</strong></>}
            {parsedQuery.availability && <> - <strong>{parsedQuery.availability}</strong></>}
          </p>
        )}
      </Card>
      <div className="catalog-result-heading">
        <div><p className="eyebrow">Recommended collection</p><h2>{recommendationLabel}</h2></div>
        <span>{books.length} book{books.length === 1 ? '' : 's'} available in this view</span>
      </div>
      {contentMatches.length > 0 && (
        <Card title="Inside-book matches" eyebrow="Indexed page content">
          <div className="content-match-list">
            {contentMatches.slice(0, 8).map((match) => (
              <article className="content-match" key={`${match.id}-${match.page_number}-${match.chunk_number}`}>
                <div>
                  <strong>{match.title}</strong>
                  <small>Page {match.page_number || 'content'} / chunk {match.chunk_number ?? 0}</small>
                </div>
                <p>{match.snippet}</p>
              </article>
            ))}
          </div>
        </Card>
      )}
      <DataState loading={results.loading} error={results.error} empty={false} onRetry={results.reload}>
        {books.length ? <div className="book-grid">{books.map((book) => (
          <BookCard
            key={book.id}
            book={book}
            role={user.role}
            onBorrow={user.role === 'student' ? handleBorrow : undefined}
            onFavorite={user.role === 'student' ? handleFavorite : undefined}
            onBookmark={user.role !== 'librarian_admin' ? handleBookmark : undefined}
            onDelete={user.role === 'librarian_admin' ? handleDelete : undefined}
          />
        ))}</div> : (
          <div className="state-panel">
            <h3>No matching books in this collection</h3>
            <p>Choose another faculty or department, select Literature and Novels, or clear the filters to see the complete catalog.</p>
          </div>
        )}
      </DataState>
    </>
  );
}

