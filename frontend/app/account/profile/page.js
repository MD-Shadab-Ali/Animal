'use client';

import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';

export default function ProfilePage() {
  const { user, token, refreshUser } = useAuth();

  const [profile, setProfile] = useState({ name: '', email: '', phone: '' });
  const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [profileErrors, setProfileErrors] = useState({});
  const [passwordErrors, setPasswordErrors] = useState({});
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);

  useEffect(() => {
    if (user) setProfile({ name: user.name || '', email: user.email || '', phone: user.phone || '' });
  }, [user]);

  const saveProfile = async (event) => {
    event.preventDefault();
    setSavingProfile(true);
    setProfileErrors({});

    try {
      const response = await apiFetch('/auth/profile', { method: 'PUT', token, body: profile });
      await refreshUser();
      toast.success(response.message);
    } catch (error) {
      setProfileErrors(error.errors || {});
      toast.error(error.message || 'Could not save your profile.');
    } finally {
      setSavingProfile(false);
    }
  };

  const savePassword = async (event) => {
    event.preventDefault();
    setSavingPassword(true);
    setPasswordErrors({});

    try {
      const response = await apiFetch('/auth/password', { method: 'PUT', token, body: passwords });
      toast.success(response.message);
      setPasswords({ current_password: '', password: '', password_confirmation: '' });
    } catch (error) {
      setPasswordErrors(error.errors || {});
      toast.error(error.message || 'Could not change your password.');
    } finally {
      setSavingPassword(false);
    }
  };

  const input = (state, setState, errors, key, label, type = 'text') => (
    <div className="mb-3">
      <label className="form-label" htmlFor={key}>{label}</label>
      <input
        id={key}
        type={type}
        className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
        value={state[key]}
        onChange={(event) => setState((current) => ({ ...current, [key]: event.target.value }))}
        required
      />
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  return (
    <div className="d-grid gap-4">
      <form className="panel" onSubmit={saveProfile}>
        <h1 className="h5 mb-4">Profile</h1>
        {input(profile, setProfile, profileErrors, 'name', 'Full name')}
        {input(profile, setProfile, profileErrors, 'email', 'Email', 'email')}
        {input(profile, setProfile, profileErrors, 'phone', 'Phone number', 'tel')}

        <button className="btn btn-brand px-4" type="submit" disabled={savingProfile}>
          {savingProfile ? 'Saving…' : 'Save changes'}
        </button>
      </form>

      <form className="panel" onSubmit={savePassword}>
        <h2 className="h5 mb-4">Change password</h2>
        {input(passwords, setPasswords, passwordErrors, 'current_password', 'Current password', 'password')}
        {input(passwords, setPasswords, passwordErrors, 'password', 'New password', 'password')}
        {input(passwords, setPasswords, passwordErrors, 'password_confirmation', 'Confirm new password', 'password')}

        <button className="btn btn-outline-brand px-4" type="submit" disabled={savingPassword}>
          {savingPassword ? 'Updating…' : 'Update password'}
        </button>
      </form>
    </div>
  );
}
