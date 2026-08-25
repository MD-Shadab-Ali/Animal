'use client';

export default function ListingField({
  form, errors, onChange, name, label,
  type = 'text', as = 'input', rows, options = [],
  colClass = 'col-md-6', required = false, hint,
}) {
  const invalid = Boolean(errors[name]);

  return (
    <div className={colClass}>
      <label className="form-label" htmlFor={name}>
        {label} {required && <span className="text-danger">*</span>}
      </label>

      {as === 'textarea' && (
        <textarea
          id={name}
          rows={rows || 3}
          className={`form-control ${invalid ? 'is-invalid' : ''}`}
          value={form[name] ?? ''}
          onChange={onChange(name)}
        />
      )}

      {as === 'select' && (
        <select
          id={name}
          className={`form-select ${invalid ? 'is-invalid' : ''}`}
          value={form[name] ?? ''}
          onChange={onChange(name)}
          required={required}
        >
          {options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
        </select>
      )}

      {as === 'input' && (
        <input
          id={name}
          type={type}
          step={type === 'number' ? 'any' : undefined}
          className={`form-control ${invalid ? 'is-invalid' : ''}`}
          value={form[name] ?? ''}
          onChange={onChange(name)}
          required={required}
        />
      )}

      {invalid && <div className="invalid-feedback d-block">{errors[name][0]}</div>}
      {hint && !invalid && <div className="form-text">{hint}</div>}
    </div>
  );
}
