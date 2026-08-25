'use client';

import { useRef, useState } from 'react';

const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf';
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * File picker for an identity document. Validates type and size in the browser
 * so people get told immediately, while the API enforces the same rules.
 */
export default function DocumentUpload({
  name, label, hint, required = false, value, onChange, error,
}) {
  const inputRef = useRef(null);
  const [localError, setLocalError] = useState(null);

  const problem = error || localError;

  const pick = (event) => {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
      setLocalError(null);
      onChange(null);
      return;
    }

    const extension = file.name.split('.').pop()?.toLowerCase();

    if (!['jpg', 'jpeg', 'png', 'webp', 'pdf'].includes(extension)) {
      setLocalError('Use a JPG, PNG, WEBP or PDF file.');
      onChange(null);
      event.target.value = '';
      return;
    }

    if (file.size > MAX_BYTES) {
      setLocalError('That file is over 5MB. Please use a smaller one.');
      onChange(null);
      event.target.value = '';
      return;
    }

    setLocalError(null);
    onChange(file);
  };

  const clear = () => {
    setLocalError(null);
    onChange(null);
    if (inputRef.current) inputRef.current.value = '';
  };

  return (
    <div className="col-md-6">
      <label className="form-label" htmlFor={name}>
        {label} {required
          ? <span className="text-danger">*</span>
          : <span className="text-soft fw-normal">(optional)</span>}
      </label>

      <input
        ref={inputRef}
        id={name}
        name={name}
        type="file"
        accept={ACCEPT}
        className={`form-control ${problem ? 'is-invalid' : ''}`}
        onChange={pick}
        aria-describedby={`${name}-hint`}
      />

      {value && (
        <div className="d-flex align-items-center justify-content-between gap-2 mt-2 p-2 bg-surface rounded">
          <span className="small text-ink text-truncate">
            <i className="bi bi-paperclip me-1" aria-hidden="true" />
            {value.name}
            <span className="text-soft ms-2">{(value.size / 1024).toFixed(0)} KB</span>
          </span>
          <button type="button" className="btn btn-link btn-sm text-danger p-0" onClick={clear}>
            Remove
          </button>
        </div>
      )}

      {problem && <div className="invalid-feedback d-block">{problem}</div>}
      {!problem && hint && <div className="form-text" id={`${name}-hint`}>{hint}</div>}
    </div>
  );
}
